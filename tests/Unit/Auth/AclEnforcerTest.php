<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Auth\AccessDeniedException;
use SuiteCRM\MCP\Auth\AclEnforcer;

final class AclEnforcerTest extends TestCase
{
    public function testRejectsModuleNotInAllowlist(): void
    {
        $enforcer = new AclEnforcer(['Accounts'], [], $this->audit());
        $this->expectException(AccessDeniedException::class);
        $enforcer->authorise('list_records', 'Users', 'corr');
    }

    public function testRejectsLocallyForbiddenAction(): void
    {
        $enforcer = new AclEnforcer(['Accounts'], ['delete'], $this->audit());
        $this->expectException(AccessDeniedException::class);
        $enforcer->authorise('delete_record', 'Accounts', 'corr');
    }

    public function testAllowsValidCall(): void
    {
        $enforcer = new AclEnforcer(['Accounts'], [], $this->audit());
        $enforcer->authorise('list_records', 'Accounts', 'corr');
        $this->addToAssertionCount(1);
    }

    public function testActionForTool(): void
    {
        $enforcer = new AclEnforcer(['Accounts'], [], $this->audit());
        $this->assertSame('delete', $enforcer->actionFor('delete_record'));
        $this->assertSame('view', $enforcer->actionFor('get_record'));
    }

    private function audit(): AuditLogger
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        return new AuditLogger($tmp);
    }
}
