<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Server;

use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Audit\CorrelationContext;
use SuiteCRM\MCP\Auth\AccessDeniedException;
use SuiteCRM\MCP\Auth\AclEnforcer;
use SuiteCRM\MCP\Auth\AuthException;
use SuiteCRM\MCP\Auth\OAuthClient;
use SuiteCRM\MCP\Config\Config;
use SuiteCRM\MCP\Crypto\SignatureVerifier;
use SuiteCRM\MCP\Http\HttpException;
use SuiteCRM\MCP\Http\SuiteCrmClient;
use SuiteCRM\MCP\Output\OutputFilter;
use SuiteCRM\MCP\RateLimit\RateLimitedException;
use SuiteCRM\MCP\RateLimit\RateLimiter;
use SuiteCRM\MCP\Replay\NonceStore;
use SuiteCRM\MCP\Tools\ToolContext;
use SuiteCRM\MCP\Tools\ToolRegistry;
use SuiteCRM\MCP\Trust\ModuleClassifier;
use SuiteCRM\MCP\Trust\ZoneAccessDeniedException;
use SuiteCRM\MCP\Trust\ZoneGuard;
use SuiteCRM\MCP\Validation\ParameterSanitizer;
use SuiteCRM\MCP\Validation\ProvenanceTracker;
use SuiteCRM\MCP\Validation\SchemaRegistry;
use SuiteCRM\MCP\Validation\SchemaValidator;
use SuiteCRM\MCP\Validation\ValidationException;

/**
 * MCP server orchestrator.
 *
 * Each `handle()` call processes exactly one inbound JSON-RPC line.
 * The control flow is intentionally linear so the security pipeline is
 * obvious — every step short-circuits with an audited rejection on
 * failure:
 *
 *   1. JSON parse + payload-size check.
 *   2. (Optional) HMAC signature verification + nonce check.
 *   3. JSON-RPC structural validation.
 *   4. Method dispatch.
 *      For `tools/call`:
 *        a) ToolRegistry lookup (static-only; no dynamic discovery).
 *        b) SchemaValidator (structure).
 *        c) ParameterSanitizer (content).
 *        d) AclEnforcer (allowlist + locally-forbidden actions).
 *        e) ZoneGuard (trust-zone gating; explicit-consent for destructive ops).
 *        f) RateLimiter (global, per-tool, recursion).
 *        g) Tool execution.
 *        h) OutputFilter (DLP + prompt-injection scan + truncation).
 *   5. JSON-RPC envelope construction + audit log of the result.
 *
 * The orchestrator is the only class that knows the full pipeline. Every
 * intermediate failure produces a typed exception and an audit event;
 * the catch ladder maps each to a deterministic JSON-RPC error code so
 * that callers cannot fingerprint internal state through error message
 * shape alone.
 */
final class McpServer
{
    private Config $config;
    private SchemaRegistry $schemas;
    private SchemaValidator $validator;
    private ParameterSanitizer $sanitizer;
    private ToolRegistry $tools;
    private AclEnforcer $acl;
    private ZoneGuard $zoneGuard;
    private ModuleClassifier $classifier;
    private RateLimiter $rateLimiter;
    private OutputFilter $output;
    private AuditLogger $audit;
    private SuiteCrmClient $http;
    private OAuthClient $auth;
    private ?SignatureVerifier $signatures;
    private ?NonceStore $nonces;
    private string $sessionId;

    public function __construct(
        Config $config,
        SchemaRegistry $schemas,
        SchemaValidator $validator,
        ParameterSanitizer $sanitizer,
        ToolRegistry $tools,
        AclEnforcer $acl,
        ZoneGuard $zoneGuard,
        ModuleClassifier $classifier,
        RateLimiter $rateLimiter,
        OutputFilter $output,
        AuditLogger $audit,
        SuiteCrmClient $http,
        OAuthClient $auth,
        ?SignatureVerifier $signatures,
        ?NonceStore $nonces
    ) {
        $this->config      = $config;
        $this->schemas     = $schemas;
        $this->validator   = $validator;
        $this->sanitizer   = $sanitizer;
        $this->tools       = $tools;
        $this->acl         = $acl;
        $this->zoneGuard   = $zoneGuard;
        $this->classifier  = $classifier;
        $this->rateLimiter = $rateLimiter;
        $this->output      = $output;
        $this->audit       = $audit;
        $this->http        = $http;
        $this->auth        = $auth;
        $this->signatures  = $signatures;
        $this->nonces      = $nonces;
        $this->sessionId   = CorrelationContext::newSessionId();

        $this->audit->event('server.start', [
            'session_id'  => $this->sessionId,
            'fingerprint' => $this->tools->capabilityFingerprint(),
            'config'      => $config->redactedSnapshot(),
            'pid'         => getmypid(),
        ]);
    }

    /**
     * Process one raw JSON-RPC line. Returns the serialised JSON response,
     * or null if the request was a notification (no `id` field).
     */
    public function handle(string $rawLine): ?string
    {
        if (strlen($rawLine) > $this->config->maxPayloadBytes()) {
            return $this->encode(JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Payload too large'));
        }

        $request = json_decode($rawLine, true);
        if (!is_array($request)) {
            $this->audit->event('rpc.parse_error', ['bytes' => strlen($rawLine)]);
            return $this->encode(JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Invalid JSON'));
        }

        $id     = $request['id']     ?? null;
        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];

        if (!is_string($method) || $method === '') {
            return $this->encode(JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Missing method'));
        }

        if ($this->config->requireSignatures()) {
            if ($this->signatures === null || $this->nonces === null) {
                return $this->encode(JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, 'Signature verification not configured'));
            }
            if (!$this->signatures->verify($request, $this->config->replayWindowSeconds())) {
                return $this->encode(JsonRpc::error($id, JsonRpc::AUTH_DENIED, 'Signature invalid'));
            }
            $sig   = $request['mcp_sig'] ?? [];
            $nonce = (string)($sig['nonce'] ?? '');
            $ts    = (int)($sig['ts']    ?? 0);
            if (!$this->nonces->consume($nonce, $ts, $this->config->replayWindowSeconds(), (string)$id)) {
                return $this->encode(JsonRpc::error($id, JsonRpc::AUTH_DENIED, 'Replay rejected'));
            }
        }

        $correlation = new CorrelationContext(
            $this->sessionId,
            CorrelationContext::newCorrelationId(),
            $this->config->username()
        );

        $this->audit->event('rpc.received', [
            'method'         => $method,
            'jsonrpc_id'     => $id,
            'correlation_id' => $correlation->correlationId(),
            'session_id'     => $correlation->sessionId(),
        ]);

        try {
            switch ($method) {
                case 'initialize':
                    return $this->encode(JsonRpc::success($id, [
                        'protocolVersion' => '2024-11-05',
                        'capabilities'    => ['tools' => (object)[]],
                        'serverInfo'      => [
                            'name'        => 'suitecrm-mcp-server',
                            'version'     => '2.0.0',
                            'fingerprint' => $this->tools->capabilityFingerprint(),
                        ],
                    ]));

                case 'tools/list':
                    return $this->encode(JsonRpc::success($id, ['tools' => $this->tools->describe()]));

                case 'tools/call':
                    return $this->handleToolCall($id, is_array($params) ? $params : [], $correlation);

                default:
                    return $this->encode(JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Unknown method: $method"));
            }
        } catch (\Throwable $e) {
            $this->audit->event('rpc.unhandled_exception', [
                'class'          => get_class($e),
                'message'        => $e->getMessage(),
                'correlation_id' => $correlation->correlationId(),
            ]);
            return $this->encode(JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, 'Internal error'));
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function handleToolCall($id, array $params, CorrelationContext $correlation): string
    {
        $toolName  = (string)($params['name']      ?? '');
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->encode(JsonRpc::error($id, JsonRpc::INVALID_PARAMS, 'arguments must be an object'));
        }

        if (!$this->tools->has($toolName)) {
            $this->audit->event('tool.invocation_denied', [
                'reason'         => 'unknown_tool',
                'tool'           => $toolName,
                'correlation_id' => $correlation->correlationId(),
            ]);
            return $this->encode(JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Unknown tool: $toolName"));
        }

        try {
            $errors = $this->validator->validate($this->schemas->schemaFor($toolName), $arguments);
            if ($errors !== []) {
                throw new ValidationException('Schema validation failed', $errors);
            }
            $arguments = $this->sanitizer->sanitiseArguments($arguments);

            if (isset($arguments['module'])) {
                $this->sanitizer->assertModuleAllowed((string)$arguments['module'], $this->config->allowedModules());
            }
            if (isset($arguments['id'])) {
                $this->sanitizer->assertIdentifier((string)$arguments['id']);
            }
            if (isset($arguments['related_id'])) {
                $this->sanitizer->assertIdentifier((string)$arguments['related_id']);
            }

            $this->rateLimiter->check($toolName, $correlation->correlationId());
            $this->rateLimiter->enter();

            try {
                $module = (string)($arguments['module'] ?? '');
                if ($module !== '') {
                    $this->acl->authorise($toolName, $module, $correlation->correlationId());
                    $zone   = $this->classifier->zoneFor($module);
                    $action = $this->acl->actionFor($toolName);
                    $this->zoneGuard->authorise($module, $zone, $action, $correlation->correlationId());
                }

                $provenance = new ProvenanceTracker();
                $this->tagInputProvenance($arguments, $provenance);

                $ctx    = new ToolContext($this->http, $this->auth, $correlation, $provenance);
                $tool   = $this->tools->get($toolName);

                $this->audit->event('tool.invoked', [
                    'tool'           => $toolName,
                    'correlation_id' => $correlation->correlationId(),
                    'module'         => $module === '' ? null : $module,
                    'arg_hash'       => hash('sha256', (string)json_encode($arguments)),
                ]);

                $result = $tool->execute($arguments, $ctx);
            } finally {
                $this->rateLimiter->leave();
            }

            $filtered = $this->output->filter($result, $correlation->correlationId(), $toolName);

            $this->audit->event('tool.completed', [
                'tool'           => $toolName,
                'correlation_id' => $correlation->correlationId(),
                'truncated'      => $filtered['truncated'],
                'dlp_hits'       => $filtered['dlp_hits'],
                'injection_hits' => $filtered['injection_hits'],
                'result_hash'    => hash('sha256', (string)json_encode($filtered['result'])),
            ]);

            $envelope = [
                'content' => [[
                    'type' => 'text',
                    'text' => (string)json_encode($filtered['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ]],
                'meta'    => [
                    'correlation_id' => $correlation->correlationId(),
                    'truncated'      => $filtered['truncated'],
                    'dlp_hits'       => $filtered['dlp_hits'],
                    'injection_hits' => $filtered['injection_hits'],
                ],
            ];

            return $this->encode(JsonRpc::success($id, $envelope));
        } catch (ValidationException $e) {
            $this->audit->event('validation.rejected', [
                'tool'           => $toolName,
                'errors'         => $e->errors(),
                'correlation_id' => $correlation->correlationId(),
            ]);
            return $this->encode(JsonRpc::error($id, JsonRpc::VALIDATION, $e->getMessage(), ['errors' => $e->errors()]));
        } catch (AccessDeniedException $e) {
            return $this->encode(JsonRpc::error($id, JsonRpc::AUTH_DENIED, $e->getMessage()));
        } catch (ZoneAccessDeniedException $e) {
            return $this->encode(JsonRpc::error($id, JsonRpc::ZONE_DENIED, $e->getMessage()));
        } catch (RateLimitedException $e) {
            return $this->encode(JsonRpc::error($id, JsonRpc::RATE_LIMITED, $e->getMessage()));
        } catch (AuthException $e) {
            $this->auth->revoke('upstream_auth_failure');
            return $this->encode(JsonRpc::error($id, JsonRpc::AUTH_DENIED, 'Upstream authentication failed'));
        } catch (HttpException $e) {
            return $this->encode(JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, 'Upstream error'));
        }
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function tagInputProvenance(array $arguments, ProvenanceTracker $provenance): void
    {
        array_walk_recursive($arguments, static function ($value) use ($provenance) {
            if (is_string($value)) {
                $provenance->tag($value, ProvenanceTracker::ORIGIN_CLIENT);
            }
        });
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
