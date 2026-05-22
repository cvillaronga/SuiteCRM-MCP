<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Auth;

use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Http\HttpException;
use SuiteCRM\MCP\Http\SuiteCrmClient;

/**
 * OAuth2 client for SuiteCRM (NSA spec 1.2).
 *
 * SuiteCRM's v8 API supports password-credentials and client-credentials
 * grants. We treat the access token as short-lived and re-authenticate
 * when it nears expiry rather than relying on a refresh token (the
 * password grant does not return one by default).
 *
 * Lifecycle controls implemented here:
 *  - Tokens are stored only in memory and never serialised to disk.
 *  - The token is rotated proactively `IDLE_GRACE_SECONDS` before its
 *    declared expiry to avoid race conditions on long calls.
 *  - An idle timeout (configurable) discards the token if the server is
 *    quiet, so a leaked token cannot be replayed long after the operator
 *    walked away.
 *  - Authentication failures are audited; the credentials are never
 *    written to the log under any circumstance.
 */
final class OAuthClient
{
    private const IDLE_GRACE_SECONDS = 30;

    private SuiteCrmClient $http;
    private TokenStore $store;
    private AuditLogger $audit;

    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private int $idleTimeoutSeconds;

    public function __construct(
        SuiteCrmClient $http,
        TokenStore $store,
        AuditLogger $audit,
        string $clientId,
        string $clientSecret,
        string $username,
        string $password,
        int $idleTimeoutSeconds
    ) {
        $this->http               = $http;
        $this->store              = $store;
        $this->audit              = $audit;
        $this->clientId           = $clientId;
        $this->clientSecret       = $clientSecret;
        $this->username           = $username;
        $this->password           = $password;
        $this->idleTimeoutSeconds = $idleTimeoutSeconds;
    }

    /**
     * Returns a currently-valid bearer token.
     *
     * @throws AuthException
     */
    public function token(): string
    {
        $existing = $this->store->current();
        if ($existing !== null && $existing->isFresh(self::IDLE_GRACE_SECONDS) && !$existing->isIdle($this->idleTimeoutSeconds)) {
            $this->store->touch();
            return $existing->accessToken;
        }
        return $this->authenticate();
    }

    /**
     * Revoke the in-memory token. Future calls will re-authenticate.
     * Honours the "invalidate compromised sessions immediately" requirement
     * (NSA spec 1.2).
     */
    public function revoke(string $reason): void
    {
        $this->store->revoke();
        $this->audit->event('auth.revoked', ['reason' => $reason]);
    }

    private function authenticate(): string
    {
        try {
            $response = $this->http->request('POST', '/Api/access_token', [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ], [
                'grant_type'    => 'password',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username'      => $this->username,
                'password'      => $this->password,
            ]);
        } catch (HttpException $e) {
            $this->audit->event('auth.failed', ['reason' => 'transport_error', 'message' => $e->getMessage()]);
            throw new AuthException('OAuth transport error: ' . $e->getMessage(), 0, $e);
        }

        if ($response['status'] !== 200) {
            $this->audit->event('auth.failed', ['reason' => 'http_status', 'status' => $response['status']]);
            throw new AuthException('OAuth authentication failed with HTTP ' . $response['status']);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_in'])) {
            $this->audit->event('auth.failed', ['reason' => 'malformed_response']);
            throw new AuthException('OAuth response missing required fields');
        }

        $token = new Token((string)$data['access_token'], time(), time() + (int)$data['expires_in']);
        $this->store->store($token);

        $this->audit->event('auth.token_refreshed', [
            'expires_at_unix' => $token->expiresAt,
            'ttl_seconds'     => (int)$data['expires_in'],
        ]);

        return $token->accessToken;
    }
}
