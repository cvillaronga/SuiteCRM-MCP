<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Trust;

/**
 * Maps SuiteCRM modules to trust zones (NSA spec 3.1).
 *
 * The default classification reflects common CRM data sensitivity:
 *  - Restricted: Cases (support cases routinely contain PII and incident detail).
 *  - Confidential: Accounts, Contacts, Opportunities, Documents.
 *  - Internal:    Leads, Tasks, Notes, Calls, Meetings, Campaigns, Emails.
 *
 * Operators can override or extend via `MCP_MODULE_ZONES` (see DESIGN.md).
 * Any module not explicitly classified defaults to Confidential — this is a
 * deny-by-default posture: unknown data is treated as sensitive until an
 * operator marks it otherwise.
 */
final class ModuleClassifier
{
    /** @var array<string,string> */
    private array $map;

    /**
     * @param array<string,string> $overrides
     */
    public function __construct(array $overrides = [])
    {
        $this->map = array_merge([
            'Accounts'      => TrustZone::CONFIDENTIAL,
            'Contacts'      => TrustZone::CONFIDENTIAL,
            'Opportunities' => TrustZone::CONFIDENTIAL,
            'Documents'     => TrustZone::CONFIDENTIAL,
            'Cases'         => TrustZone::RESTRICTED,
            'Leads'         => TrustZone::INTERNAL,
            'Tasks'         => TrustZone::INTERNAL,
            'Notes'         => TrustZone::INTERNAL,
            'Calls'         => TrustZone::INTERNAL,
            'Meetings'      => TrustZone::INTERNAL,
            'Campaigns'     => TrustZone::INTERNAL,
            'Emails'        => TrustZone::INTERNAL,
        ], $overrides);

        foreach ($this->map as $module => $zone) {
            if (!TrustZone::isValid($zone)) {
                throw new \InvalidArgumentException("Invalid zone '$zone' for module '$module'");
            }
        }
    }

    public function zoneFor(string $module): string
    {
        return $this->map[$module] ?? TrustZone::CONFIDENTIAL;
    }
}
