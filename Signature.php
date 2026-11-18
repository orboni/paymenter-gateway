<?php

namespace Paymenter\Extensions\Gateways\Orboni;

/**
 * `Webhook-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, the
 * scheme Stripe uses. After a secret is rolled Orboni signs with both for a
 * while, so any `v1` may match.
 */
class Signature
{
    /** Seconds a signature stays good: an old event replayed later is refused. */
    public const TOLERANCE = 300;

    public static function verify(string $secret, string $body, ?string $header, ?int $now = null): bool
    {
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($timestamp <= 0 || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
