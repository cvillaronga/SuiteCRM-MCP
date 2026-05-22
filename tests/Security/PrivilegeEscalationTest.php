<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Security;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Auth\AccessDeniedException;
use SuiteCRM\MCP\Auth\AclEnforcer;
use SuiteCRM\MCP\Trust\ModuleClassifier;
use SuiteCRM\MCP\Trust\ZoneAccessDeniedException;
use SuiteCRM\MCP\Trust\ZoneGuard;

/**
 * Verifies that destructive escalation paths are blocked even when one
 * defence layer is bypassed:
 *  - ACL allowlist mis-config -> ZoneGuard still blocks destructive
 *    operations on Confidential+ zones.
 *  - Destructive flag set but high-risk flag missing -> Restricted is
 *    still blocked.
 */
final class PrivilegeEscalationTest extends TestCase
{
    public function testZoneGuardBlocksEvenIfAclMisconfiguredToAllow(): void
    {
        $acl   = new AclEnforcer(['Accounts'], [], $this->audit());
        $guard = new ZoneGuard(false, false, $this->audit());
        $cls   = new ModuleClassifier();

        // ACL allows it...
        $acl->authorise('delete_record', 'Accounts', 'corr');

        // ...but the zone guard refuses because allow_destructive=false.
        $this->expectException(ZoneAccessDeniedException::class);
        $guard->authorise('Accounts', $cls->zoneFor('Accounts'), 'delete', 'corr');
    }

    public function testRestrictedZoneNeedsBothFlags(): void
    {
        $guard = new ZoneGuard(true, false, $this->audit());
        $cls   = new ModuleClassifier();
        $this->expectException(ZoneAccessDeniedException::class);
        $guard->authorise('Cases', $cls->zoneFor('Cases'), 'delete', 'corr');
    }

    public function testAclDeniesUnauthorisedModule(): void
    {
        $acl = new AclEnforcer(['Accounts'], [], $this->audit());
        $this->expectException(AccessDeniedException::class);
        $acl->authorise('list_records', 'Users', 'corr');
    }

    private function audit(): AuditLogger
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        return new AuditLogger($tmp);
    }
}
