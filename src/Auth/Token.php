<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Auth;

/**
 * Value object describing an active OAuth bearer token.
 *
 * The class is intentionally minimal — it is never serialised, never
 * logged, and never compared by anything other than itself. `__debugInfo`
 * scrubs the token material so any accidental var_dump in development
 * surfaces "[redacted]" rather than the secret.
 */
final class Token
{
    public string $accessToken;
    public int $issuedAt;
    public int $expiresAt;
    public int $lastUsedAt;

    public function __construct(string $accessToken, int $issuedAt, int $expiresAt)
    {
        $this->accessToken = $accessToken;
        $this->issuedAt    = $issuedAt;
        $this->expiresAt   = $expiresAt;
        $this->lastUsedAt  = $issuedAt;
    }

    public function isFresh(int $graceSeconds): bool
    {
        return time() + $graceSeconds < $this->expiresAt;
    }

    public function isIdle(int $idleSeconds): bool
    {
        return (time() - $this->lastUsedAt) > $idleSeconds;
    }

    public function touch(): void
    {
        $this->lastUsedAt = time();
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted]',
            'issuedAt'    => $this->issuedAt,
            'expiresAt'   => $this->expiresAt,
            'lastUsedAt'  => $this->lastUsedAt,
        ];
    }
}
