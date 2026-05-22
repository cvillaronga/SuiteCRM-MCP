<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

use SuiteCRM\MCP\Validation\SchemaRegistry;

/**
 * Static tool registry (NSA spec 3.4).
 *
 * Dynamic tool discovery is *not* supported by design. Every tool that
 * this MCP server exposes is registered in code, reviewed at build time,
 * and tied to a schema in {@see SchemaRegistry}. There is no path for an
 * upstream component to "publish" or "register" a new tool at runtime —
 * the registry is constructed once and is immutable afterwards.
 *
 * This is the concrete defence against:
 *  - tool poisoning;
 *  - naming-collision attacks;
 *  - parasitic toolchain hijacking via dynamic discovery;
 *  - silent capability growth (NSA spec 7.2 — capability changes only
 *    land via code review and version bumps, which is itself the audit
 *    trail).
 */
final class ToolRegistry
{
    /** @var array<string,AbstractTool> */
    private array $tools;
    private SchemaRegistry $schemas;

    /**
     * @param array<int,AbstractTool> $tools
     */
    public function __construct(SchemaRegistry $schemas, array $tools)
    {
        $this->schemas = $schemas;
        $this->tools   = [];
        foreach ($tools as $tool) {
            $name = $tool->name();
            if (!$schemas->has($name)) {
                throw new \InvalidArgumentException("Tool '$name' has no registered schema.");
            }
            if (isset($this->tools[$name])) {
                throw new \InvalidArgumentException("Duplicate tool registration: $name");
            }
            $this->tools[$name] = $tool;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): AbstractTool
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Unknown tool: $name");
        }
        return $this->tools[$name];
    }

    /**
     * Return the MCP tools/list response payload. Includes the schema so
     * the client knows what to send; the schema is the authoritative
     * contract.
     *
     * @return array<int,array<string,mixed>>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->tools as $name => $tool) {
            $out[] = [
                'name'        => $name,
                'description' => $tool->description(),
                'inputSchema' => $this->schemas->schemaFor($name),
            ];
        }
        return $out;
    }

    /**
     * Capability fingerprint (NSA spec 7.2). A stable hash that changes
     * whenever the exposed tool surface changes. Operators can pin this
     * and compare against deployments to detect drift.
     */
    public function capabilityFingerprint(): string
    {
        $surface = $this->describe();
        return hash('sha256', (string)json_encode($surface, JSON_UNESCAPED_SLASHES));
    }
}
