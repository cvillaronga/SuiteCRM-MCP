<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

final class CreateRecordTool extends AbstractTool
{
    public function name(): string        { return 'create_record'; }
    public function description(): string { return 'Create a new record in a SuiteCRM module.'; }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function execute(array $arguments, ToolContext $ctx)
    {
        $module   = rawurlencode((string)$arguments['module']);
        $endpoint = "/Api/V8/module/$module";

        $payload = [
            'data' => [
                'type'       => (string)$arguments['module'],
                'attributes' => (array)$arguments['data'],
            ],
        ];

        $response = $ctx->http->request('POST', $endpoint, $this->authHeaders($ctx), $payload);
        $this->assertOk($response['status'], $this->name());
        return $this->decodeJson($response['body']);
    }
}
