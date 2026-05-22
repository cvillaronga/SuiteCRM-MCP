<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Auth;

/**
 * In-memory token store.
 *
 * For the stdio model there is no need for cross-process token storage —
 * each server invocation is its own session. The store therefore only
 * exists to centralise the "current token" lifecycle so external callers
 * never touch token internals.
 *
 * Concrete behaviour for NSA spec 1.2:
 *  - `current()` returns null after `revoke()` until `store()` is called.
 *  - `touch()` records the last use so idle timeouts work.
 *  - Tokens are never serialised, never written to disk.
 */
final class TokenStore
{
    private ?Token $token = null;

    public function current(): ?Token
    {
        return $this->token;
    }

    public function store(Token $token): void
    {
        $this->token = $token;
    }

    public function touch(): void
    {
        if ($this->token !== null) {
            $this->token->touch();
        }
    }

    public function revoke(): void
    {
        $this->token = null;
    }
}
