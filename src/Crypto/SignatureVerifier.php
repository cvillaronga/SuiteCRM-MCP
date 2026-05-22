<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Crypto;

use SuiteCRM\MCP\Audit\AuditLogger;

/**
 * HMAC-SHA256 message signing middleware (NSA spec 5.1).
 *
 * Activation: disabled by default for the stdio transport because there
 * is no network in the loop — a local pipe between MCP client and server
 * has no integrity threat that signing would solve. The class exists so
 * that when a future HTTP transport is added the contract is already in
 * place; the JSON-RPC handler calls `verify()` whenever
 * `Config::requireSignatures()` returns true, which is the documented
 * activation switch (see SECURITY.md).
 *
 * Wire format expected from the client when active:
 *  { "jsonrpc": "...", "id": ..., "method": ..., "params": ...,
 *    "mcp_sig": { "alg":"HMAC-SHA256", "ts":1717000000, "nonce":"...",
 *                 "sig":"hex(hmac(secret, ts.nonce.method.body))" } }
 *
 * Verification:
 *  - Constant-time HMAC comparison via `hash_equals`.
 *  - Timestamp must fall within the replay window (spec 5.2 lives in
 *    {@see \SuiteCRM\MCP\Replay\NonceStore}).
 *  - Empty signing secrets cause the verifier to refuse to start —
 *    enabling signing without a key is always a configuration error.
 */
final class SignatureVerifier
{
    private string $secret;
    private AuditLogger $audit;

    public function __construct(string $secret, AuditLogger $audit)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Signing secret must not be empty when signature verification is enabled.');
        }
        $this->secret = $secret;
        $this->audit  = $audit;
    }

    /**
     * @param array<string,mixed> $envelope The full JSON-RPC request as decoded array.
     */
    public function verify(array $envelope, int $replayWindowSeconds): bool
    {
        $sig = $envelope['mcp_sig'] ?? null;
        if (!is_array($sig) || !isset($sig['alg'], $sig['ts'], $sig['nonce'], $sig['sig'])) {
            $this->audit->event('signature.invalid', ['reason' => 'missing_fields']);
            return false;
        }
        if ($sig['alg'] !== 'HMAC-SHA256') {
            $this->audit->event('signature.invalid', ['reason' => 'unsupported_alg', 'alg' => $sig['alg']]);
            return false;
        }
        $ts = (int)$sig['ts'];
        if (abs(time() - $ts) > $replayWindowSeconds) {
            $this->audit->event('signature.invalid', ['reason' => 'timestamp_outside_window']);
            return false;
        }

        unset($envelope['mcp_sig']);
        $body     = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $this->audit->event('signature.invalid', ['reason' => 'reencode_failed']);
            return false;
        }
        $method   = (string)($envelope['method'] ?? '');
        $material = $ts . '.' . $sig['nonce'] . '.' . $method . '.' . $body;
        $expected = hash_hmac('sha256', $material, $this->secret);

        if (!hash_equals($expected, (string)$sig['sig'])) {
            $this->audit->event('signature.invalid', ['reason' => 'hmac_mismatch']);
            return false;
        }
        return true;
    }
}
