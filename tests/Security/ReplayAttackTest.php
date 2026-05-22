<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Security;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Replay\NonceStore;

final class ReplayAttackTest extends TestCase
{
    public function testSecondConsumptionIsRejected(): void
    {
        $store = new NonceStore($this->audit());
        $this->assertTrue($store->consume('nonce-aaaa-bbbb', time(), 60, 'corr'));
        $this->assertFalse($store->consume('nonce-aaaa-bbbb', time(), 60, 'corr'));
    }

    public function testExpiredNoncesEvicted(): void
    {
        $store = new NonceStore($this->audit());
        // Issue with negative window so it expires immediately.
        $store->consume('nonce-eviction', time() - 1000, 1, 'corr');
        // After eviction, the same nonce can be reused.
        $this->assertTrue($store->consume('nonce-eviction', time(), 60, 'corr'));
    }

    private function audit(): AuditLogger
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        return new AuditLogger($tmp);
    }
}
