<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Audit;

/**
 * SIEM-side fan-out (NSA spec 6.2).
 *
 * This class is intentionally a thin interface in the live runtime:
 * SuiteCRM-MCP is a local stdio process and does not initiate outbound
 * connections to security tooling on its own — that would itself be a
 * defence-in-depth violation.
 *
 * Production deployments are expected to forward the JSONL audit log via
 * Filebeat, Promtail, Vector, or a sidecar tail process. This emitter
 * therefore only:
 *  - decides which event names are "interesting" (security-relevant),
 *  - writes a marker line to a configured endpoint file if provided.
 *
 * If `$endpoint` is empty (default), the emitter is a no-op.
 *
 * Wiring HTTP push to a remote SIEM is left to operators because it
 * requires a credentialled outbound channel — see SECURITY.md for the
 * documented activation path.
 */
final class SiemEmitter
{
    private const SECURITY_EVENTS = [
        'auth.failed',
        'auth.token_refreshed',
        'auth.revoked',
        'validation.rejected',
        'rate_limit.exceeded',
        'zone.denied',
        'output.dropped',
        'output.injection_detected',
        'tool.invocation_denied',
        'capability.diff_detected',
        'replay.detected',
        'signature.invalid',
    ];

    private string $endpoint;

    public function __construct(string $endpoint = '')
    {
        $this->endpoint = $endpoint;
    }

    public function isInterested(string $event): bool
    {
        return in_array($event, self::SECURITY_EVENTS, true);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function emit(string $event, array $payload): void
    {
        if ($this->endpoint === '') {
            return;
        }
        $line = json_encode(['siem_event' => $event, 'payload' => $payload], JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($this->endpoint, $line, FILE_APPEND | LOCK_EX);
    }
}
