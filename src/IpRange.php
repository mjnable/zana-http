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
            $subnet = @inet_pton($parts[0]);
            if ($subnet === false || strlen($subnet) !== strlen($address)) {
                continue;
            }
            $bits = $bits === null ? strlen($subnet) * 8 : (int) $bits;
            if (self::prefix($address, $bits) === self::prefix($subnet, $bits)) {
                return true;
            }
        }

        return false;
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
