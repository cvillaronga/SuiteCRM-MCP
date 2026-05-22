<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Trust;

use SuiteCRM\MCP\Audit\AuditLogger;

/**
 * Enforces cross-zone access policy (NSA spec 3.1).
 *
 * Two policies are enforced here:
 *  - Destructive operations on Confidential and higher require an explicit
 *    operator opt-in (`MCP_ALLOW_DESTRUCTIVE`, and for Restricted/Regulated
 *    `MCP_ALLOW_DESTRUCTIVE_CONFIDENTIAL`). This is the concrete interpretation
 *    of NSA spec 7.1 (explicit consent for sensitive actions) for a stdio
 *    server: the consent is captured as a deployment-time configuration
 *    change, which is itself an auditable event.
 *  - Every zone transition is logged so forensic reconstruction can show
 *    which calls escalated privilege.
 */
final class ZoneGuard
{
    private bool $allowDestructive;
    private bool $allowDestructiveHigh;
    private AuditLogger $audit;

    public function __construct(bool $allowDestructive, bool $allowDestructiveHigh, AuditLogger $audit)
    {
        $this->allowDestructive     = $allowDestructive;
        $this->allowDestructiveHigh = $allowDestructiveHigh;
        $this->audit                = $audit;
    }

    /**
     * @throws ZoneAccessDeniedException
     */
    public function authorise(string $module, string $zone, string $operation, string $correlationId): void
    {
        $this->audit->event('zone.access', [
            'module'         => $module,
            'zone'           => $zone,
            'operation'      => $operation,
            'correlation_id' => $correlationId,
        ]);

        $isDestructive = in_array($operation, ['create', 'update', 'delete', 'relate'], true);
        if (!$isDestructive) {
            return;
        }

        $level = TrustZone::level($zone);

        if ($level >= TrustZone::level(TrustZone::CONFIDENTIAL) && !$this->allowDestructive) {
            $this->deny($module, $zone, $operation, $correlationId, 'destructive_op_not_permitted');
        }
        if ($level >= TrustZone::level(TrustZone::RESTRICTED) && !$this->allowDestructiveHigh) {
            $this->deny($module, $zone, $operation, $correlationId, 'destructive_op_high_risk_zone_not_permitted');
        }
    }

    private function deny(string $module, string $zone, string $operation, string $correlationId, string $reason): void
    {
        $this->audit->event('zone.denied', [
            'module'         => $module,
            'zone'           => $zone,
            'operation'      => $operation,
            'reason'         => $reason,
            'correlation_id' => $correlationId,
        ]);
        throw new ZoneAccessDeniedException(
            sprintf('Operation "%s" on module "%s" (zone=%s) denied: %s', $operation, $module, $zone, $reason)
        );
    }
}
