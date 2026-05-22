<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Validation;

/**
 * Tracks the origin of parameter values across a request (NSA spec 2.2).
 *
 * Each tool invocation receives a fresh tracker. Strings entering the system
 * are tagged with their origin label ("mcp_client", "suitecrm", "tool_output").
 * Before a value crosses a trust boundary — for example, a string returned by
 * one tool reused as input to another — callers consult this tracker to
 * decide whether the propagation is permitted.
 *
 * The stdio MCP model in this codebase has a single inbound trust zone (the
 * configured MCP client) so the tracker primarily defends future use cases
 * where tools chain. It is wired in now so the contract is uniform and any
 * future cross-tool forwarder cannot bypass it.
 */
final class ProvenanceTracker
{
    public const ORIGIN_CLIENT      = 'mcp_client';
    public const ORIGIN_SUITECRM    = 'suitecrm';
    public const ORIGIN_TOOL_OUTPUT = 'tool_output';
    public const ORIGIN_INTERNAL    = 'internal';

    /** @var array<string,string> hash → origin */
    private array $tags = [];

    public function tag(string $value, string $origin): void
    {
        if (!in_array($origin, [self::ORIGIN_CLIENT, self::ORIGIN_SUITECRM, self::ORIGIN_TOOL_OUTPUT, self::ORIGIN_INTERNAL], true)) {
            throw new \InvalidArgumentException("Unknown provenance origin: $origin");
        }
        $this->tags[$this->key($value)] = $origin;
    }

    public function originOf(string $value): ?string
    {
        return $this->tags[$this->key($value)] ?? null;
    }

    /**
     * Reject forwarding values that originated from untrusted sources into
     * downstream tools. Callers invoke this at every chained-tool boundary.
     *
     * @throws ValidationException
     */
    public function assertMayForward(string $value, string $intoTool): void
    {
        $origin = $this->originOf($value);
        if ($origin === null) {
            throw new ValidationException(
                sprintf('Refusing to forward untagged value into tool "%s" (provenance unknown).', $intoTool)
            );
        }
        if ($origin === self::ORIGIN_TOOL_OUTPUT) {
            throw new ValidationException(
                sprintf('Refusing to forward tool-output value into tool "%s" without explicit re-tagging.', $intoTool)
            );
        }
    }

    private function key(string $value): string
    {
        return hash('sha256', $value);
    }
}
