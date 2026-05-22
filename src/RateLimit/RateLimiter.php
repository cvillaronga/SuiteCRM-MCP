<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\RateLimit;

use SuiteCRM\MCP\Audit\AuditLogger;

/**
 * Token-bucket rate limiter (NSA spec 8.1).
 *
 * Two buckets exist per server instance:
 *  - a global bucket capping total tool invocations per minute;
 *  - per-tool buckets so a single noisy tool cannot starve others.
 *
 * The buckets are in-process — fine for stdio because each MCP client
 * spawns one server process. The limiter does *not* attempt to share
 * state across processes; the operator's MCP client is the natural
 * single point of contention.
 *
 * Additionally tracks a recursive-depth counter (NSA spec 8.2): the
 * server records how many tool invocations have happened inside the
 * current handler call frame. If the depth exceeds the configured
 * limit (the MCP server is currently a flat invocation model, so this
 * is a future-proofing guard) we abort and emit `fatigue.detected`.
 */
final class RateLimiter
{
    private int $perMinute;
    private float $globalTokens;
    private float $lastRefill;
    /** @var array<string, array{tokens:float, last:float}> */
    private array $perTool = [];
    private int $recursionDepth = 0;
    private int $maxRecursion;
    private AuditLogger $audit;

    public function __construct(int $perMinute, int $maxRecursion, AuditLogger $audit)
    {
        $this->perMinute    = max(1, $perMinute);
        $this->maxRecursion = max(1, $maxRecursion);
        $this->globalTokens = (float)$this->perMinute;
        $this->lastRefill   = microtime(true);
        $this->audit        = $audit;
    }

    /**
     * @throws RateLimitedException
     */
    public function check(string $tool, string $correlationId): void
    {
        $this->refill();

        if ($this->globalTokens < 1.0) {
            $this->reject($tool, 'global_bucket_empty', $correlationId);
        }

        if (!isset($this->perTool[$tool])) {
            $this->perTool[$tool] = ['tokens' => (float)$this->perMinute, 'last' => microtime(true)];
        }
        $this->refillTool($tool);
        if ($this->perTool[$tool]['tokens'] < 1.0) {
            $this->reject($tool, 'per_tool_bucket_empty', $correlationId);
        }

        $this->globalTokens             -= 1.0;
        $this->perTool[$tool]['tokens'] -= 1.0;
    }

    public function enter(): void
    {
        $this->recursionDepth++;
        if ($this->recursionDepth > $this->maxRecursion) {
            $this->audit->event('fatigue.detected', [
                'reason' => 'recursion_depth_exceeded',
                'depth'  => $this->recursionDepth,
                'limit'  => $this->maxRecursion,
            ]);
            $this->recursionDepth--;
            throw new RateLimitedException('Maximum tool recursion depth exceeded');
        }
    }

    public function leave(): void
    {
        if ($this->recursionDepth > 0) {
            $this->recursionDepth--;
        }
    }

    private function refill(): void
    {
        $now             = microtime(true);
        $elapsed         = $now - $this->lastRefill;
        $newTokens       = $elapsed * ($this->perMinute / 60.0);
        $this->globalTokens = min((float)$this->perMinute, $this->globalTokens + $newTokens);
        $this->lastRefill   = $now;
    }

    private function refillTool(string $tool): void
    {
        $now      = microtime(true);
        $elapsed  = $now - $this->perTool[$tool]['last'];
        $newToks  = $elapsed * ($this->perMinute / 60.0);
        $this->perTool[$tool]['tokens'] = min((float)$this->perMinute, $this->perTool[$tool]['tokens'] + $newToks);
        $this->perTool[$tool]['last']   = $now;
    }

    private function reject(string $tool, string $reason, string $correlationId): void
    {
        $this->audit->event('rate_limit.exceeded', [
            'tool'           => $tool,
            'reason'         => $reason,
            'correlation_id' => $correlationId,
        ]);
        throw new RateLimitedException("Rate limit exceeded for tool '$tool' ($reason)");
    }
}
