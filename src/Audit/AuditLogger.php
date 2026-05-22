<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Audit;

/**
 * Structured audit logger (NSA spec 6.1).
 *
 * Emits a single JSON object per line ("JSONL") so the output can be
 * ingested by ElasticSearch, Loki, Splunk, or any SIEM forwarder without
 * additional parsing.
 *
 * Every event carries:
 *  - timestamp (UTC, RFC3339 with microseconds);
 *  - event name (dotted notation, e.g. `tool.invoked`);
 *  - session and correlation IDs;
 *  - the configured operator identity;
 *  - a SHA-256 hash of the serialised payload (tamper-evidence anchor —
 *    not a substitute for log signing, but it lets a downstream log
 *    integrity scanner detect post-hoc edits inside a single log line).
 *
 * Streams are written with `LOCK_EX` to keep multi-process appends safe.
 * For stdio servers there is normally only one process, but operators
 * occasionally fan out via a supervisor.
 *
 * Sensitive values (secrets, full record payloads on Restricted modules)
 * MUST be redacted by callers before reaching this class — the logger
 * trusts its caller to scrub PII; it does not introspect the payload.
 */
final class AuditLogger
{
    /** @var resource|null */
    private $stream;
    private string $logPath;
    private ?SiemEmitter $siem;

    public function __construct(string $logPath, ?SiemEmitter $siem = null)
    {
        $this->logPath = $logPath;
        $this->siem    = $siem;
        $resource      = fopen($logPath, 'ab');
        if ($resource === false) {
            throw new \RuntimeException("Unable to open audit log: $logPath");
        }
        $this->stream = $resource;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function event(string $name, array $payload): void
    {
        $record = [
            'ts'      => $this->nowRfc3339(),
            'event'   => $name,
            'payload' => $payload,
        ];
        $body            = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            // Fail-safe: never lose an audit event because of bad data.
            $record['payload'] = ['__serialisation_error' => json_last_error_msg()];
            $body              = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $hash   = hash('sha256', (string)$body);
        $line   = (string)$body . "\t" . $hash . "\n";

        if ($this->stream !== null) {
            flock($this->stream, LOCK_EX);
            fwrite($this->stream, $line);
            fflush($this->stream);
            flock($this->stream, LOCK_UN);
        }

        if ($this->siem !== null && $this->siem->isInterested($name)) {
            $this->siem->emit($name, $payload + ['__hash' => $hash]);
        }
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function nowRfc3339(): string
    {
        $micro = (string)microtime(true);
        $micro = str_pad(substr(strrchr($micro, '.') ?: '.000000', 1), 6, '0', STR_PAD_RIGHT);
        return gmdate('Y-m-d\\TH:i:s') . '.' . $micro . 'Z';
    }
}
