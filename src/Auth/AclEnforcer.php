<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Auth;

use SuiteCRM\MCP\Audit\AuditLogger;

/**
 * Per-tool authorisation pre-check (NSA spec 1.1).
 *
 * Authoritative authorisation for any CRUD action against SuiteCRM lives
 * inside SuiteCRM's own ACL system, which we cannot consult in-process
 * because we are not running inside the SuiteCRM application. The honest
 * mapping for a remote MCP-over-stdio server is therefore:
 *
 *  1. We enforce a *deny-by-default* allowlist locally — the operator
 *     declares which modules this MCP instance may touch (Config::allowedModules).
 *     Anything not on the list is rejected before we ever speak to SuiteCRM.
 *  2. We map the MCP tool name to the equivalent SuiteCRM ACL action
 *     (view/list/edit/delete/import). If a tool requires an action that
 *     the operator marks as locally forbidden (e.g. delete disabled in
 *     this deployment), we deny without an outbound call.
 *  3. SuiteCRM's API returns 401/403 if its own ACL refuses the
 *     authenticated user; that response is treated as authoritative —
 *     we surface it as a denial rather than retrying or escalating.
 *
 * This is documented in SECURITY.md so operators understand exactly which
 * layer is enforcing which decision.
 */
final class AclEnforcer
{
    private const TOOL_TO_ACTION = [
        'list_records'   => 'list',
        'get_record'     => 'view',
        'create_record'  => 'edit',
        'update_record'  => 'edit',
        'delete_record'  => 'delete',
        'search_records' => 'list',
        'relate_records' => 'edit',
    ];

    /** @var array<int,string> */
    private array $allowedModules;
    /** @var array<int,string> */
    private array $forbiddenActions;
    private AuditLogger $audit;

    /**
     * @param array<int,string> $allowedModules
     * @param array<int,string> $forbiddenActions
     */
    public function __construct(array $allowedModules, array $forbiddenActions, AuditLogger $audit)
    {
        $this->allowedModules   = $allowedModules;
        $this->forbiddenActions = $forbiddenActions;
        $this->audit            = $audit;
    }

    /**
     * @throws AccessDeniedException
     */
    public function authorise(string $tool, string $module, string $correlationId): void
    {
        if (!isset(self::TOOL_TO_ACTION[$tool])) {
            $this->deny($tool, $module, 'unknown_tool', $correlationId);
        }
        $action = self::TOOL_TO_ACTION[$tool];

        if (!in_array($module, $this->allowedModules, true)) {
            $this->deny($tool, $module, 'module_not_in_allowlist', $correlationId);
        }

        if (in_array($action, $this->forbiddenActions, true)) {
            $this->deny($tool, $module, 'action_locally_forbidden', $correlationId);
        }

        $this->audit->event('authz.granted', [
            'tool'           => $tool,
            'module'         => $module,
            'action'         => $action,
            'correlation_id' => $correlationId,
        ]);
    }

    public function actionFor(string $tool): string
    {
        return self::TOOL_TO_ACTION[$tool] ?? 'unknown';
    }

    private function deny(string $tool, string $module, string $reason, string $correlationId): void
    {
        $this->audit->event('authz.denied', [
            'tool'           => $tool,
            'module'         => $module,
            'reason'         => $reason,
            'correlation_id' => $correlationId,
        ]);
        throw new AccessDeniedException("Tool '$tool' denied for module '$module': $reason");
    }
}
