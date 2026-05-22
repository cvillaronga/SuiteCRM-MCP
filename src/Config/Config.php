<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Config;

/**
 * Centralised configuration loader.
 *
 * Replaces the previous hand-rolled `.env` parser. Reads values that have
 * already been hydrated into `$_ENV` (the bootstrap is responsible for that,
 * using `vlucas/phpdotenv`) and exposes them through strongly-typed accessors.
 *
 * Threat-model notes:
 *  - No silent fallbacks for security-sensitive values. Missing credentials
 *    raise a hard error instead of letting the server start in an undefined
 *    state.
 *  - Every value the rest of the codebase consumes is funnelled through this
 *    object so that the trust boundary between the environment and the
 *    runtime is explicit and auditable.
 *  - Secrets are never serialised by `__debugInfo` to keep them out of logs
 *    and stack traces.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values;

    /**
     * @param array<string,string> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Hydrate from `$_ENV` and validate required keys.
     *
     * @param array<string,string> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $required = [
            'SUITECRM_URL',
            'SUITECRM_CLIENT_ID',
            'SUITECRM_CLIENT_SECRET',
            'SUITECRM_USERNAME',
            'SUITECRM_PASSWORD',
        ];

        $missing = [];
        foreach ($required as $key) {
            if (!isset($env[$key]) || trim($env[$key]) === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new ConfigException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }

        $url = rtrim((string)$env['SUITECRM_URL'], '/');
        if (!self::isHttpsOrLoopback($url)) {
            throw new ConfigException(
                'SUITECRM_URL must use HTTPS (loopback HTTP allowed for local dev only).'
            );
        }

        return new self([
            'suitecrm_url'           => $url,
            'client_id'              => (string)$env['SUITECRM_CLIENT_ID'],
            'client_secret'          => (string)$env['SUITECRM_CLIENT_SECRET'],
            'username'               => (string)$env['SUITECRM_USERNAME'],
            'password'               => (string)$env['SUITECRM_PASSWORD'],
            'allowed_modules'        => (string)($env['MCP_ALLOWED_MODULES']
                ?? 'Accounts,Contacts,Leads,Opportunities,Cases,Calls,Meetings,Tasks,Notes,Emails,Campaigns,Documents'),
            'allow_destructive'      => (string)($env['MCP_ALLOW_DESTRUCTIVE'] ?? 'false'),
            'allow_destructive_high' => (string)($env['MCP_ALLOW_DESTRUCTIVE_CONFIDENTIAL'] ?? 'false'),
            'audit_log_path'         => (string)($env['MCP_AUDIT_LOG'] ?? 'php://stderr'),
            'rate_limit_per_minute'  => (string)($env['MCP_RATE_LIMIT_PER_MINUTE'] ?? '60'),
            'max_payload_bytes'      => (string)($env['MCP_MAX_PAYLOAD_BYTES'] ?? '65536'),
            'token_idle_seconds'     => (string)($env['MCP_TOKEN_IDLE_SECONDS'] ?? '900'),
            'request_timeout'        => (string)($env['MCP_HTTP_TIMEOUT'] ?? '15'),
            'require_signatures'     => (string)($env['MCP_REQUIRE_SIGNATURES'] ?? 'false'),
            'signing_secret'         => (string)($env['MCP_SIGNING_SECRET'] ?? ''),
            'replay_window_seconds'  => (string)($env['MCP_REPLAY_WINDOW_SECONDS'] ?? '300'),
            'siem_endpoint'          => (string)($env['MCP_SIEM_ENDPOINT'] ?? ''),
        ]);
    }

    public function suiteCrmUrl(): string         { return $this->values['suitecrm_url']; }
    public function clientId(): string            { return $this->values['client_id']; }
    public function clientSecret(): string        { return $this->values['client_secret']; }
    public function username(): string            { return $this->values['username']; }
    public function password(): string            { return $this->values['password']; }

    /** @return array<int,string> */
    public function allowedModules(): array
    {
        $raw = array_map('trim', explode(',', $this->values['allowed_modules']));
        return array_values(array_filter($raw, static fn($m) => $m !== ''));
    }

    public function allowDestructive(): bool          { return $this->boolish('allow_destructive'); }
    public function allowDestructiveHighRisk(): bool  { return $this->boolish('allow_destructive_high'); }
    public function auditLogPath(): string            { return $this->values['audit_log_path']; }
    public function rateLimitPerMinute(): int         { return max(1, (int)$this->values['rate_limit_per_minute']); }
    public function maxPayloadBytes(): int            { return max(1024, (int)$this->values['max_payload_bytes']); }
    public function tokenIdleSeconds(): int           { return max(60, (int)$this->values['token_idle_seconds']); }
    public function requestTimeout(): int             { return max(1, (int)$this->values['request_timeout']); }
    public function requireSignatures(): bool         { return $this->boolish('require_signatures'); }
    public function signingSecret(): string           { return $this->values['signing_secret']; }
    public function replayWindowSeconds(): int        { return max(30, (int)$this->values['replay_window_seconds']); }
    public function siemEndpoint(): string            { return $this->values['siem_endpoint']; }

    /**
     * Used by audit logs and error responses. Never includes secrets.
     *
     * @return array<string,mixed>
     */
    public function redactedSnapshot(): array
    {
        return [
            'suitecrm_url'           => $this->values['suitecrm_url'],
            'allowed_modules'        => $this->allowedModules(),
            'allow_destructive'      => $this->allowDestructive(),
            'allow_destructive_high' => $this->allowDestructiveHighRisk(),
            'rate_limit_per_minute'  => $this->rateLimitPerMinute(),
            'max_payload_bytes'      => $this->maxPayloadBytes(),
            'token_idle_seconds'     => $this->tokenIdleSeconds(),
            'require_signatures'     => $this->requireSignatures(),
            'siem_endpoint_set'      => $this->values['siem_endpoint'] !== '',
        ];
    }

    public function __debugInfo(): array
    {
        return $this->redactedSnapshot();
    }

    private function boolish(string $key): bool
    {
        $v = strtolower(trim($this->values[$key] ?? 'false'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private static function isHttpsOrLoopback(string $url): bool
    {
        if (strpos($url, 'https://') === 0) {
            return true;
        }
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
