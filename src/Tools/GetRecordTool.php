<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

final class GetRecordTool extends AbstractTool
{
    public function name(): string        { return 'get_record'; }
    public function description(): string { return 'Retrieve a single SuiteCRM record by ID.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $module   = rawurlencode((string)$arguments['module']);
        $id       = rawurlencode((string)$arguments['id']);
        $endpoint = "/Api/V8/module/$module/$id";

        $response = $ctx->http->request('GET', $endpoint, $this->authHeaders($ctx));
        $this->assertOk($response['status'], $this->name());
        return $this->decodeJson($response['body']);
    }
}
