<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Audit\SiemEmitter;

final class AuditLoggerTest extends TestCase
{
    public function testEventsAreJsonlWithHash(): void
    {
        $tmp    = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        $logger = new AuditLogger($tmp);
        $logger->event('test.event', ['key' => 'value']);
        unset($logger);

        $content = (string)file_get_contents($tmp);
        $this->assertNotSame('', $content);

        $lines = array_values(array_filter(explode("\n", $content)));
        $this->assertCount(1, $lines);

        [$json, $hash] = explode("\t", $lines[0]);
        $decoded = json_decode($json, true);
        $this->assertSame('test.event', $decoded['event']);
        $this->assertSame(hash('sha256', $json), $hash);
    }

    public function testSiemFiltersOnlySecurityEvents(): void
    {
        $endpoint = tempnam(sys_get_temp_dir(), 'mcp_siem_');
        $siem     = new SiemEmitter($endpoint);
        $tmp      = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        $logger   = new AuditLogger($tmp, $siem);

        $logger->event('rpc.received', ['noise' => true]);
        $logger->event('auth.failed', ['reason' => 'bad_password']);
        unset($logger);

        $content = (string)file_get_contents($endpoint);
        $this->assertStringContainsString('auth.failed', $content);
        $this->assertStringNotContainsString('rpc.received', $content);
    }
}
