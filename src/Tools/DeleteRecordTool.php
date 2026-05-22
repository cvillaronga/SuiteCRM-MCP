<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

final class DeleteRecordTool extends AbstractTool
{
    public function name(): string        { return 'delete_record'; }
    public function description(): string { return 'Delete a record. Destructive: gated by ZoneGuard and ACL allowlist.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $module   = rawurlencode((string)$arguments['module']);
        $id       = rawurlencode((string)$arguments['id']);
        $endpoint = "/Api/V8/module/$module/$id";

        $response = $ctx->http->request('DELETE', $endpoint, $this->authHeaders($ctx));
        $this->assertOk($response['status'], $this->name());
        // SuiteCRM returns 204 with no body on success.
        if (trim($response['body']) === '') {
            return ['deleted' => true];
        }
        return $this->decodeJson($response['body']);
    }
}
