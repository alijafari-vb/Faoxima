<?php

namespace Faoxima\Orders\Pricing;

final class MarketRates
{
    private static function fetch(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS => 3500,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Faoxima-FX/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
    }

    /** Rates are returned in toman per coin, with the source that supplied each rate. */
    public static function get(array $coins): array
    {
        $coins = array_values(array_intersect(array_unique($coins), ['USDT', 'TRX', 'TON']));
        if ($coins === []) {
            return [];
        }
        $rates = [];
        // Nobitex serves these markets in rial. TON is not supported by this endpoint.
        $nobitex = array_intersect($coins, ['USDT', 'TRX']);
        if ($nobitex !== []) {
            $symbols = implode(',', array_map('strtolower', $nobitex));
            $data = json_decode(self::fetch(FX_NOBITEX_ENDPOINT . '?srcCurrency=' . rawurlencode($symbols) . '&dstCurrency=rls') ?? '', true);
            if (($data['status'] ?? null) === 'ok') {
                foreach ($nobitex as $coin) {
                    $market = $data['stats'][strtolower($coin) . '-rls'] ?? [];
                    $rial = ExchangeRates::fxParseRateValue($market['latest'] ?? null);
                    if ($rial !== null && ($market['isClosed'] ?? null) === false) {
                        $rates[$coin] = ['rate' => $rial / 10, 'source' => 'nobitex'];
                    }
                }
            }
        }

        $missing = array_diff($coins, array_keys($rates));
        if ($missing !== []) {
            $swap = self::fetch(FX_SWAPWALLET_ENDPOINT);
            if ($swap !== null) {
                foreach ($missing as $coin) {
                    $quote = ExchangeRates::fxRateParseMarketResponse($swap, $coin . '/IRT');
                    if ($quote['ok']) {
                        $rates[$coin] = ['rate' => $quote['rate'], 'source' => 'swapwallet'];
                    }
                }
            }
        }

        // Wallex's TMN order book is in toman. Both sides must be valid and close enough.
        foreach (array_diff($coins, array_keys($rates)) as $coin) {
            $data = json_decode(self::fetch(FX_WALLEX_ENDPOINT . '?symbol=' . $coin . 'TMN') ?? '', true);
            $ask = ExchangeRates::fxParseRateValue($data['result']['ask'][0]['price'] ?? null);
            $bid = ExchangeRates::fxParseRateValue($data['result']['bid'][0]['price'] ?? null);
            if ($ask !== null && $bid !== null && $ask >= $bid && $ask <= $bid * 1.10) {
                $rates[$coin] = ['rate' => $bid + ($ask - $bid) / 2, 'source' => 'wallex'];
            }
        }
        return $rates;
    }
}
