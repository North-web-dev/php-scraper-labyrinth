<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * CIDR membership tests for IPv4 and IPv6, plus IP normalisation.
 *
 * Pure, stateless helpers. Used by the static crawler IP-range safety net and
 * for normalising client IPs into stable store keys.
 */
final class IpMatcher
{
    /**
     * Is $ip inside the CIDR block $cidr (e.g. "192.0.2.0/24" or "2001:db8::/32")?
     *
     * Returns false for malformed input or mixed IPv4/IPv6 comparisons rather
     * than throwing — a verification helper must never fatal on bad data.
     */
    public static function inRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            // Treat a bare address as a /32 or /128.
            return self::normalise($ip) !== null && self::normalise($ip) === self::normalise($cidr);
        }

        [$subnet, $bitsRaw] = explode('/', $cidr, 2);
        if (!ctype_digit($bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Must be the same address family (same binary length).
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainderBits)) - 1) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Is $ip inside ANY of the given CIDR blocks?
     *
     * @param iterable<string> $cidrs
     */
    public static function inAnyRange(string $ip, iterable $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise an IP to its canonical packed-then-expanded string form, or
     * null if it is not a valid address. Useful for stable comparison/keys.
     */
    public static function normalise(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        $out = @inet_ntop($bin);

        return $out === false ? null : $out;
    }
}
