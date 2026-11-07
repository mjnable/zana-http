<?php

declare(strict_types=1);

namespace Zana\Http;

/**
 * Matches IPv4 and IPv6 addresses against addresses and CIDR ranges.
 */
final class IpRange
{
    /**
     * @param list<string> $ranges e.g. ['10.0.0.0/8', '2001:db8::/32', '203.0.113.7']
     */
    public static function contains(array $ranges, string $ip): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }

        foreach ($ranges as $range) {
            $parts = explode('/', $range, 2);
            $bits = $parts[1] ?? null;
            $subnet = @inet_pton(trim($parts[0]));
            if ($subnet === false || strlen($subnet) !== strlen($address)) {
                continue;
            }
            $bits = self::bits($bits, strlen($subnet) * 8);
            if ($bits !== null && self::prefix($address, $bits) === self::prefix($subnet, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ranges that cannot be read, which match nothing (2.19.2): before, a
     * prefix such as "/abc" was read as /0, which matches every address.
     *
     * @param list<mixed> $ranges
     * @return list<string>
     */
    public static function invalid(array $ranges): array
    {
        $invalid = [];
        foreach ($ranges as $range) {
            if (!is_string($range)) {
                $invalid[] = get_debug_type($range);
                continue;
            }
            $parts = explode('/', $range, 2);
            $subnet = @inet_pton(trim($parts[0]));
            if ($subnet === false || self::bits($parts[1] ?? null, strlen($subnet) * 8) === null) {
                $invalid[] = $range;
            }
        }

        return $invalid;
    }

    private static function bits(?string $bits, int $max): ?int
    {
        if ($bits === null) {
            return $max;
        }
        $bits = trim($bits);

        return ctype_digit($bits) && (int) $bits <= $max ? (int) $bits : null;
    }

    private static function prefix(string $packed, int $bits): string
    {
        $bytes = intdiv($bits, 8);
        $prefix = substr($packed, 0, $bytes);
        $rest = $bits % 8;
        if ($rest > 0 && isset($packed[$bytes])) {
            $prefix .= chr(ord($packed[$bytes]) & (0xFF << (8 - $rest)) & 0xFF);
        }

        return $prefix;
    }
}
