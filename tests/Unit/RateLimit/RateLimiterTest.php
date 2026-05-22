<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\RateLimit\RateLimitedException;
use SuiteCRM\MCP\RateLimit\RateLimiter;

final class RateLimiterTest extends TestCase
{
    public function testAllowsUpToBucketSize(): void
    {
        $limiter = new RateLimiter(5, 4, $this->audit());
        for ($i = 0; $i < 5; $i++) {
            $limiter->check('list_records', 'c' . $i);
        }
        $this->addToAssertionCount(1);
    }

    public function testBlocksAfterBucketEmpty(): void
    {
        $limiter = new RateLimiter(2, 4, $this->audit());
        $limiter->check('list_records', 'a');
        $limiter->check('list_records', 'b');
        $this->expectException(RateLimitedException::class);
        $limiter->check('list_records', 'c');
    }

    public function testRecursionDepthEnforced(): void
    {
        $limiter = new RateLimiter(100, 2, $this->audit());
        $limiter->enter();
        $limiter->enter();
        $this->expectException(RateLimitedException::class);
        $limiter->enter();
    }

    private function audit(): AuditLogger
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        return new AuditLogger($tmp);
    }
}
