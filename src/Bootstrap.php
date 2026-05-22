<?php
declare(strict_types=1);

namespace SuiteCRM\MCP;

use Dotenv\Dotenv;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Audit\SiemEmitter;
use SuiteCRM\MCP\Auth\AclEnforcer;
use SuiteCRM\MCP\Auth\OAuthClient;
use SuiteCRM\MCP\Auth\TokenStore;
use SuiteCRM\MCP\Config\Config;
use SuiteCRM\MCP\Crypto\SignatureVerifier;
use SuiteCRM\MCP\Http\SuiteCrmClient;
use SuiteCRM\MCP\Output\ContentPolicy;
use SuiteCRM\MCP\Output\OutputFilter;
use SuiteCRM\MCP\Output\PromptInjectionScanner;
use SuiteCRM\MCP\RateLimit\RateLimiter;
use SuiteCRM\MCP\Replay\NonceStore;
use SuiteCRM\MCP\Server\McpServer;
use SuiteCRM\MCP\Tools\CreateRecordTool;
use SuiteCRM\MCP\Tools\DeleteRecordTool;
use SuiteCRM\MCP\Tools\GetRecordTool;
use SuiteCRM\MCP\Tools\ListRecordsTool;
use SuiteCRM\MCP\Tools\RelateRecordsTool;
use SuiteCRM\MCP\Tools\SearchRecordsTool;
use SuiteCRM\MCP\Tools\ToolRegistry;
use SuiteCRM\MCP\Tools\UpdateRecordTool;
use SuiteCRM\MCP\Trust\ModuleClassifier;
use SuiteCRM\MCP\Trust\ZoneGuard;
use SuiteCRM\MCP\Validation\ParameterSanitizer;
use SuiteCRM\MCP\Validation\SchemaRegistry;
use SuiteCRM\MCP\Validation\SchemaValidator;

/**
 * Composition root.
 *
 * One method, one purpose: build a fully-wired {@see McpServer}. Splitting
 * construction from the executable entry point lets the integration tests
 * exercise the exact same wiring used in production by passing an
 * `$envOverrides` argument.
 */
final class Bootstrap
{
    /**
     * @param array<string,string> $envOverrides
     */
    public static function build(string $projectRoot, array $envOverrides = []): McpServer
    {
        if (file_exists($projectRoot . '/.env')) {
            // Use vlucas/phpdotenv per the existing composer.json dependency
            // rather than the previous hand-rolled parser, which lacked
            // quoting and escaping rules and routinely mis-parsed values
            // that contained `=` or whitespace.
            $dotenv = Dotenv::createImmutable($projectRoot);
            $dotenv->safeLoad();
        }
        foreach ($envOverrides as $k => $v) {
            $_ENV[$k] = $v;
        }

        $config = Config::fromEnvironment($_ENV);

        $siem  = new SiemEmitter($config->siemEndpoint());
        $audit = new AuditLogger($config->auditLogPath(), $siem);

        $schemas    = new SchemaRegistry();
        $validator  = new SchemaValidator();
        $sanitizer  = new ParameterSanitizer();

        $classifier = new ModuleClassifier();
        $zoneGuard  = new ZoneGuard($config->allowDestructive(), $config->allowDestructiveHighRisk(), $audit);
        $acl        = new AclEnforcer($config->allowedModules(), [], $audit);

        $rateLimit  = new RateLimiter($config->rateLimitPerMinute(), 4, $audit);
        $scanner    = new PromptInjectionScanner();
        $policy     = new ContentPolicy();
        $output     = new OutputFilter($scanner, $policy, $audit);

        $http       = new SuiteCrmClient($config->suiteCrmUrl(), $config->requestTimeout());
        $tokenStore = new TokenStore();
        $oauth      = new OAuthClient(
            $http,
            $tokenStore,
            $audit,
            $config->clientId(),
            $config->clientSecret(),
            $config->username(),
            $config->password(),
            $config->tokenIdleSeconds()
        );

        $signatures = null;
        $nonces     = null;
        if ($config->requireSignatures()) {
            $signatures = new SignatureVerifier($config->signingSecret(), $audit);
            $nonces     = new NonceStore($audit);
        }

        $tools = new ToolRegistry($schemas, [
            new ListRecordsTool($sanitizer),
            new GetRecordTool(),
            new CreateRecordTool(),
            new UpdateRecordTool(),
            new DeleteRecordTool(),
            new SearchRecordsTool($acl, $sanitizer),
            new RelateRecordsTool(),
        ]);

        return new McpServer(
            $config,
            $schemas,
            $validator,
            $sanitizer,
            $tools,
            $acl,
            $zoneGuard,
            $classifier,
            $rateLimit,
            $output,
            $audit,
            $http,
            $oauth,
            $signatures,
            $nonces
        );
    }
}
