<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Audit;

/**
 * Per-request correlation identifiers (NSA spec 1.3, 6.1).
 *
 * One context exists per inbound JSON-RPC request. The `correlationId` flows
 * through every audit event, every downstream HTTP call, and is included in
 * the JSON-RPC response so that the MCP client and server-side logs can be
 * tied together during incident response.
 *
 * `sessionId` identifies the process instance (one stdio server == one
 * session). The fingerprint deliberately includes process id + start time +
 * a random component; it is *not* a security-sensitive identifier and is
 * not used for authentication — it exists purely for forensic correlation.
 */
final class CorrelationContext
{
    private string $sessionId;
    private string $correlationId;
    private string $identity;

    public function __construct(string $sessionId, string $correlationId, string $identity)
    {
        $this->sessionId     = $sessionId;
        $this->correlationId = $correlationId;
        $this->identity      = $identity;
    }

    public static function newSessionId(): string
    {
        $pid       = (string)getmypid();
        $startTime = (string)hrtime(true);
        $entropy   = bin2hex(random_bytes(6));
        return substr(hash('sha256', "$pid:$startTime:$entropy"), 0, 24);
    }

    public static function newCorrelationId(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function sessionId(): string     { return $this->sessionId; }
    public function correlationId(): string { return $this->correlationId; }
    public function identity(): string      { return $this->identity; }

    public function withCorrelationId(string $correlationId): self
    {
        return new self($this->sessionId, $correlationId, $this->identity);
    }

    /** @return array<string,string> */
    public function toMetadata(): array
    {
        return [
            'session_id'     => $this->sessionId,
            'correlation_id' => $this->correlationId,
            'identity'       => $this->identity,
        ];
    }
}
