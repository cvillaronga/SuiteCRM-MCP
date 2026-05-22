<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

use SuiteCRM\MCP\Auth\AclEnforcer;
use SuiteCRM\MCP\Auth\AccessDeniedException;
use SuiteCRM\MCP\Http\HttpException;
use SuiteCRM\MCP\Validation\ParameterSanitizer;

/**
 * Cross-module search.
 *
 * Each module is re-checked through the ACL enforcer because the caller
 * supplies the module list at invocation time. We never accept the
 * client-supplied module list as pre-authorised — every entry walks
 * through the same allowlist that single-module calls use.
 *
 * The previous implementation interpolated `%{$query}%` directly into the
 * URL. Here, `query` has already been schema-validated for length and
 * cleared of control characters by ParameterSanitizer; we additionally
 * `rawurlencode` it when composing the filter parameter.
 */
final class SearchRecordsTool extends AbstractTool
{
    private AclEnforcer $acl;

    public function __construct(AclEnforcer $acl, ParameterSanitizer $sanitizer)
    {
        $this->acl = $acl;
        unset($sanitizer); // declared for future use; suppresses unused param warnings
    }

    public function name(): string        { return 'search_records'; }
    public function description(): string { return 'Search records by name across one or more modules.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $query   = (string)$arguments['query'];
        $modules = isset($arguments['modules']) && is_array($arguments['modules'])
            ? array_values($arguments['modules'])
            : ['Accounts', 'Contacts', 'Leads'];

        $results = [];
        foreach ($modules as $module) {
            try {
                $this->acl->authorise($this->name(), (string)$module, $ctx->correlation->correlationId());
            } catch (AccessDeniedException $e) {
                $results[(string)$module] = ['__skipped' => 'access_denied'];
                continue;
            }

            $endpoint = sprintf(
                '/Api/V8/module/%s?filter[name][LIKE]=%s',
                rawurlencode((string)$module),
                rawurlencode('%' . $query . '%')
            );

            try {
                $response = $ctx->http->request('GET', $endpoint, $this->authHeaders($ctx));
                if ($response['status'] >= 200 && $response['status'] < 300) {
                    $decoded = json_decode($response['body'], true);
                    if (is_array($decoded) && isset($decoded['data'])) {
                        $results[(string)$module] = $decoded['data'];
                    }
                } else {
                    $results[(string)$module] = ['__error' => 'http_' . $response['status']];
                }
            } catch (HttpException $e) {
                $results[(string)$module] = ['__error' => 'transport'];
            }
        }
        return $results;
    }
}
