<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Bootstrap;
use SuiteCRM\MCP\Server\McpServer;

/**
 * End-to-end behaviour tests for the JSON-RPC pipeline. These run the
 * actual orchestrator (no mocks) and assert that requests which never
 * hit the upstream SuiteCRM (initialize, tools/list, denied/invalid
 * tool calls) produce the expected envelopes and error codes.
 *
 * Outbound HTTP is *not* exercised here — that requires a live SuiteCRM
 * and is out of scope for unit/integration tests. The server's
 * pipeline-up-to-HTTP is fully covered.
 */
final class ServerIntegrationTest extends TestCase
{
    private McpServer $server;

    protected function setUp(): void
    {
        $_ENV['SUITECRM_URL']            = 'https://localhost';
        $_ENV['SUITECRM_CLIENT_ID']      = 'cid';
        $_ENV['SUITECRM_CLIENT_SECRET']  = 'csec';
        $_ENV['SUITECRM_USERNAME']       = 'tester';
        $_ENV['SUITECRM_PASSWORD']       = 'pw';
        $_ENV['MCP_AUDIT_LOG']           = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        $_ENV['MCP_ALLOWED_MODULES']     = 'Accounts,Contacts,Leads';
        $_ENV['MCP_RATE_LIMIT_PER_MINUTE'] = '100';

        $this->server = Bootstrap::build(dirname(__DIR__, 2));
    }

    public function testInitialize(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $this->assertSame(1, $response['id']);
        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
        $this->assertArrayHasKey('fingerprint', $response['result']['serverInfo']);
    }

    public function testToolsListExposesAllSevenTools(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $tools    = array_column($response['result']['tools'], 'name');
        sort($tools);
        $this->assertSame([
            'create_record',
            'delete_record',
            'get_record',
            'list_records',
            'relate_records',
            'search_records',
            'update_record',
        ], $tools);
    }

    public function testToolsCallWithUnknownToolIsRejected(): void
    {
        $response = $this->call([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => ['name' => 'evil_tool', 'arguments' => []],
        ]);
        $this->assertSame(-32601, $response['error']['code']);
    }

    public function testToolsCallWithInvalidSchemaIsRejected(): void
    {
        $response = $this->call([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'tools/call',
            'params'  => ['name' => 'get_record', 'arguments' => ['module' => 'Accounts']],
        ]);
        $this->assertSame(-32003, $response['error']['code']);
    }

    public function testToolsCallWithModuleOutsideAllowlistIsDenied(): void
    {
        $response = $this->call([
            'jsonrpc' => '2.0',
            'id'      => 5,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'get_record',
                'arguments' => ['module' => 'Users', 'id' => 'abc'],
            ],
        ]);
        // ParameterSanitizer fires first (module not in allowlist) -> -32003 validation.
        $this->assertContains($response['error']['code'], [-32001, -32003]);
    }

    public function testToolsCallDeleteOnConfidentialDeniedByDefault(): void
    {
        $response = $this->call([
            'jsonrpc' => '2.0',
            'id'      => 6,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'delete_record',
                'arguments' => ['module' => 'Accounts', 'id' => 'abc123'],
            ],
        ]);
        $this->assertSame(-32004, $response['error']['code']);
    }

    public function testInvalidJsonReturnsParseError(): void
    {
        $raw      = '{ this is not json';
        $rawResp  = $this->server->handle($raw);
        $response = json_decode((string)$rawResp, true);
        $this->assertSame(-32700, $response['error']['code']);
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function call(array $request): array
    {
        $line = json_encode($request, JSON_UNESCAPED_SLASHES);
        $resp = $this->server->handle((string)$line);
        $this->assertNotNull($resp);
        return (array)json_decode((string)$resp, true);
    }
}
