<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Replay;

use SuiteCRM\MCP\Audit\AuditLogger;

/**
 * In-memory nonce store for replay protection (NSA spec 5.2).
 *
 * As with {@see \SuiteCRM\MCP\Crypto\SignatureVerifier} this is wired in
 * for transport-future-proofing and is inert for the local stdio model
 * (no nonces are validated unless signatures are required).
 *
 * Properties:
 *  - Bounded LRU keyed by nonce hash; defaults to 10 000 entries to
 *    cap memory regardless of attacker volume.
 *  - Each entry stores the expiry time; on every check we evict
 *    expired entries opportunistically.
 *  - `consume()` is the only state-changing method and is idempotent
 *    only in the negative: a nonce previously seen always returns
 *    false until its window expires.
 */
final class NonceStore
{
    private int $maxEntries;
    private AuditLogger $audit;
    /** @var array<string,int> nonce-hash → expires_at */
    private array $seen = [];

    public function __construct(AuditLogger $audit, int $maxEntries = 10000)
    {
        $this->audit      = $audit;
        $this->maxEntries = $maxEntries;
    }

    public function consume(string $nonce, int $issuedAt, int $windowSeconds, string $correlationId): bool
    {
        if ($nonce === '' || strlen($nonce) < 8) {
            $this->audit->event('replay.detected', [
                'reason'         => 'weak_nonce',
                'correlation_id' => $correlationId,
            ]);
            return false;
        }
        $key = hash('sha256', $nonce);
        $now = time();
        $this->evictExpired($now);

        if (isset($this->seen[$key])) {
            $this->audit->event('replay.detected', [
                'reason'         => 'nonce_reuse',
                'correlation_id' => $correlationId,
            ]);
            return false;
        }
        $this->seen[$key] = $issuedAt + $windowSeconds;
        if (count($this->seen) > $this->maxEntries) {
            // Drop the oldest 10% of entries — simple LRU surrogate.
            asort($this->seen);
            $this->seen = array_slice($this->seen, (int)($this->maxEntries * 0.1), null, true);
        }
        return true;
    }

    private function evictExpired(int $now): void
    {
        foreach ($this->seen as $hash => $expiry) {
            if ($expiry < $now) {
                unset($this->seen[$hash]);
            }
        }
    }
}
