<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\Trust;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Trust\ModuleClassifier;
use SuiteCRM\MCP\Trust\TrustZone;
use SuiteCRM\MCP\Trust\ZoneAccessDeniedException;
use SuiteCRM\MCP\Trust\ZoneGuard;

final class ZoneGuardTest extends TestCase
{
    public function testReadOnlyAlwaysAllowed(): void
    {
        $guard = new ZoneGuard(false, false, $this->audit());
        $guard->authorise('Accounts', TrustZone::CONFIDENTIAL, 'view', 'corr');
        $this->addToAssertionCount(1);
    }

    public function testDestructiveBlockedOnConfidentialByDefault(): void
    {
        $guard = new ZoneGuard(false, false, $this->audit());
        $this->expectException(ZoneAccessDeniedException::class);
        $guard->authorise('Accounts', TrustZone::CONFIDENTIAL, 'delete', 'corr');
    }

    public function testDestructiveAllowedOnInternalWithoutFlag(): void
    {
        $guard = new ZoneGuard(false, false, $this->audit());
        $guard->authorise('Leads', TrustZone::INTERNAL, 'update', 'corr');
        $this->addToAssertionCount(1);
    }

    public function testRestrictedRequiresHighFlag(): void
    {
        $guard = new ZoneGuard(true, false, $this->audit());
        $this->expectException(ZoneAccessDeniedException::class);
        $guard->authorise('Cases', TrustZone::RESTRICTED, 'delete', 'corr');
    }

    public function testRestrictedWithHighFlagPermitted(): void
    {
        $guard = new ZoneGuard(true, true, $this->audit());
        $guard->authorise('Cases', TrustZone::RESTRICTED, 'delete', 'corr');
        $this->addToAssertionCount(1);
    }

    public function testClassifierDefaultZones(): void
    {
        $classifier = new ModuleClassifier();
        $this->assertSame(TrustZone::CONFIDENTIAL, $classifier->zoneFor('Accounts'));
        $this->assertSame(TrustZone::RESTRICTED, $classifier->zoneFor('Cases'));
        $this->assertSame(TrustZone::INTERNAL, $classifier->zoneFor('Leads'));
        // Unknown module falls back to Confidential (deny-by-default).
        $this->assertSame(TrustZone::CONFIDENTIAL, $classifier->zoneFor('CustomSecretModule'));
    }

    private function audit(): AuditLogger
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        return new AuditLogger($tmp);
    }
}
