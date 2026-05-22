<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

final class UpdateRecordTool extends AbstractTool
{
    public function name(): string        { return 'update_record'; }
    public function description(): string { return 'Update an existing SuiteCRM record.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $moduleRaw = (string)$arguments['module'];
        $idRaw     = (string)$arguments['id'];
        $module    = rawurlencode($moduleRaw);
        $id        = rawurlencode($idRaw);
        $endpoint  = "/Api/V8/module/$module/$id";

        $payload = [
            'data' => [
                'type'       => $moduleRaw,
                'id'         => $idRaw,
                'attributes' => (array)$arguments['data'],
            ],
        ];

        $response = $ctx->http->request('PATCH', $endpoint, $this->authHeaders($ctx), $payload);
        $this->assertOk($response['status'], $this->name());
        return $this->decodeJson($response['body']);
    }
}
