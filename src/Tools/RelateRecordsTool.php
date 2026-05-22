<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

final class RelateRecordsTool extends AbstractTool
{
    public function name(): string        { return 'relate_records'; }
    public function description(): string { return 'Create a relationship between two SuiteCRM records.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $module    = rawurlencode((string)$arguments['module']);
        $id        = rawurlencode((string)$arguments['id']);
        $linkField = rawurlencode((string)$arguments['link_field']);
        $endpoint  = "/Api/V8/module/$module/$id/relationships/$linkField";

        $payload = [
            'data' => [
                'type' => (string)$arguments['link_field'],
                'id'   => (string)$arguments['related_id'],
            ],
        ];

        $response = $ctx->http->request('POST', $endpoint, $this->authHeaders($ctx), $payload);
        $this->assertOk($response['status'], $this->name());
        return $this->decodeJson($response['body']);
    }
}
