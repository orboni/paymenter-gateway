<?php

namespace Paymenter\Extensions\Gateways\Orboni;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The currencies Orboni prices in (`GET /v1/currencies`), kept an hour. One
 * whose rate is missing or stale says `available: false`: the gateway is not
 * offered in it, though a payment already made in it is still counted. Orboni
 * not answering keeps the last list it gave for a day; with none at all,
 * dollars only.
 */
class Currencies
{
    private const FRESH_SECONDS = 3600;

    private const KEPT_SECONDS = 86400;

    /**
     * @return array<string, array{decimals: int, available: bool, usd: float|null}> by upper-case code; `usd`: dollars per unit, null with no rate
     */
    public static function all(Api $api): array
    {
        $key = 'orboni:currencies:'.$api->fingerprint();
        $kept = Cache::get($key);
        if (is_array($kept) && ($kept['at'] ?? 0) > time() - self::FRESH_SECONDS && is_array($kept['list'] ?? null)) {
            return $kept['list'];
        }

        try {
            $list = self::parse($api->currencies());
        } catch (ApiException $e) {
            Log::warning('Orboni: could not read the currencies: '.$e->getMessage());
            $list = [];
        }

        if ($list === []) {
            return is_array($kept['list'] ?? null) ? $kept['list'] : ['USD' => ['decimals' => 2, 'available' => true, 'usd' => 1.0]];
        }

        Cache::put($key, ['at' => time(), 'list' => $list], self::KEPT_SECONDS);

        return $list;
    }

    /** @return array{decimals: int, available: bool, usd: float|null}|null a currency a payment can be priced in now */
    public static function available(Api $api, string $code): ?array
    {
        $currency = self::all($api)[strtoupper($code)] ?? null;

        return $currency !== null && $currency['available'] ? $currency : null;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, array{decimals: int, available: bool, usd: float|null}>
     */
    private static function parse(array $answer): array
    {
        $list = [];
        foreach ((array) ($answer['data'] ?? []) as $currency) {
            if (! is_array($currency) || ! is_string($currency['code'] ?? null)) {
                continue;
            }
            $code = strtoupper($currency['code']);
            $rate = is_array($currency['exchange_rate'] ?? null) ? ($currency['exchange_rate']['usd'] ?? null) : null;
            $list[$code] = [
                'decimals' => max(0, (int) ($currency['decimals'] ?? 2)),
                'available' => ($currency['available'] ?? false) === true,
                'usd' => $code === 'USD' ? 1.0 : (is_numeric($rate) ? (float) $rate : null),
            ];
        }

        return $list;
    }
}
