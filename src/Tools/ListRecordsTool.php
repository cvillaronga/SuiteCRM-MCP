<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

use SuiteCRM\MCP\Validation\ParameterSanitizer;

final class ListRecordsTool extends AbstractTool
{
    private ParameterSanitizer $sanitizer;

    public function __construct(ParameterSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    public function name(): string        { return 'list_records'; }
    public function description(): string { return 'List records from a SuiteCRM module with optional structured filter.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $module = (string)$arguments['module'];
        $limit  = (int)($arguments['limit']  ?? 20);
        $offset = (int)($arguments['offset'] ?? 0);
        $filter = isset($arguments['filter']) && is_array($arguments['filter'])
            ? $this->sanitizer->sanitiseFilter($arguments['filter'])
            : [];

        $page = (int)floor($offset / max(1, $limit)) + 1;
        /*
         * URL is composed exclusively from validated components:
         *  - module already matched the strict module pattern (SchemaValidator)
         *    and was checked against the operator allowlist (AclEnforcer).
         *  - limit and page are integers within bounded ranges.
         *  - filter values are URL-encoded as a final defence; they cannot
         *    inject path components because urlencode replaces `/`, `?`, `&`.
         */
        $endpoint = sprintf('/Api/V8/module/%s?page[size]=%d&page[number]=%d', $module, $limit, $page);
        foreach ($filter as $field => $value) {
            $endpoint .= '&filter[' . rawurlencode($field) . ']=' . rawurlencode($value);
        }

        $response = $ctx->http->request('GET', $endpoint, $this->authHeaders($ctx));
        $this->assertOk($response['status'], $this->name());
        return $this->decodeJson($response['body']);
    }
}
