<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Trust;

/**
 * Data classification zones, as required by NSA spec 3.1.
 *
 * Ordering is significant: a higher numerical level implies stricter
 * controls. Cross-zone access (from lower to higher) requires explicit
 * authorization escalation; the reverse (high-to-low data movement) is
 * the sensitive direction and is gated by {@see ZoneGuard}.
 *
 * Using class constants rather than a PHP 8.1 enum keeps the runtime
 * compatible with PHP 7.4 per `composer.json`.
 */
final class TrustZone
{
    public const PUBLIC       = 'public';
    public const INTERNAL     = 'internal';
    public const CONFIDENTIAL = 'confidential';
    public const RESTRICTED   = 'restricted';
    public const REGULATED    = 'regulated';

    private const LEVELS = [
        self::PUBLIC       => 0,
        self::INTERNAL     => 1,
        self::CONFIDENTIAL => 2,
        self::RESTRICTED   => 3,
        self::REGULATED    => 4,
    ];

    public static function level(string $zone): int
    {
        if (!isset(self::LEVELS[$zone])) {
            throw new \InvalidArgumentException("Unknown trust zone: $zone");
        }
        return self::LEVELS[$zone];
    }

    public static function isValid(string $zone): bool
    {
        return isset(self::LEVELS[$zone]);
    }

    /** @return array<int,string> */
    public static function all(): array
    {
        return array_keys(self::LEVELS);
    }
}
