<?php

declare(strict_types=1);

// Start the fixture with: php -S 127.0.0.1:43500 scripts/tests/market-rates.php
// Run this file with one of: primary, partial, swapwallet, wallex, unavailable.
if (PHP_SAPI === 'cli-server') {
    [$mode, $source] = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')) + [null, null];
    header('Content-Type: application/json');
    if ($source === 'swapwallet' && in_array($mode, ['primary', 'partial', 'swapwallet'], true)) {
        $result = $mode === 'partial'
            ? ['USDT/IRT' => 100000, 'TRX/IRT' => 12000]
            : ['usdt/irt' => '100,000', 'Ton/IRT' => 300000, 'TRX/IRT' => 13000];
        echo json_encode(['status' => 'OK', 'result' => $result]);
    } elseif ($source === 'nobitex' && in_array($mode, ['primary', 'partial'], true)
        && ($_GET['dstCurrency'] ?? '') === 'rls' && !str_contains($_GET['srcCurrency'] ?? '', 'ton')) {
        echo json_encode(['status' => 'ok', 'stats' => [
            'usdt-rls' => ['latest' => '1100010', 'isClosed' => false],
            'trx-rls' => ['latest' => '125010', 'isClosed' => $mode === 'partial'],
        ]]);
    } elseif ($source === 'wallex') {
        [$ask, $bid] = match ([$mode, $_GET['symbol'] ?? '']) {
            ['partial', 'TONTMN'] => [280020, 280000],
            ['wallex', 'USDTTMN'] => [105020, 105000],
            ['wallex', 'TRXTMN'] => [14000, 13980],
            default => [140000, 100000],
        };
        echo json_encode(['result' => ['ask' => [['price' => $ask]], 'bid' => [['price' => $bid]]]]);
    } else {
        http_response_code(503);
        echo '{}';
    }
    return;
}

$mode = $argv[1] ?? '';
$base = 'http://127.0.0.1:43500/' . $mode;
define('FX_SWAPWALLET_ENDPOINT', $base . '/swapwallet');
define('FX_NOBITEX_ENDPOINT', $base . '/nobitex');
define('FX_WALLEX_ENDPOINT', $base . '/wallex');
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
restore_exception_handler();

$expected = match ($mode) {
    'primary' => [
        'USDT' => ['rate' => 110001.0, 'source' => 'nobitex'],
        'TRX' => ['rate' => 12501.0, 'source' => 'nobitex'],
        'TON' => ['rate' => 300000.0, 'source' => 'swapwallet'],
    ],
    'partial' => [
        'USDT' => ['rate' => 110001.0, 'source' => 'nobitex'],
        'TRX' => ['rate' => 12000.0, 'source' => 'swapwallet'],
        'TON' => ['rate' => 280010.0, 'source' => 'wallex'],
    ],
    'swapwallet' => [
        'USDT' => ['rate' => 100000.0, 'source' => 'swapwallet'],
        'TRX' => ['rate' => 13000.0, 'source' => 'swapwallet'],
        'TON' => ['rate' => 300000.0, 'source' => 'swapwallet'],
    ],
    'wallex' => [
        'USDT' => ['rate' => 105010.0, 'source' => 'wallex'],
        'TRX' => ['rate' => 13990.0, 'source' => 'wallex'],
    ],
    'unavailable' => [],
    default => throw new RuntimeException('Select a fixture mode.'),
};
$actual = \Faoxima\Orders\Pricing\MarketRates::get(['USDT', 'TRX', 'TON']);
if ($actual !== $expected) {
    throw new RuntimeException('Unexpected market rates: ' . json_encode($actual));
}
$rates = \Faoxima\Payments\Gateways\Tronado\Runtime::requireTronRates(['TRX', 'USD']);
$expectedCheckout = isset($expected['TRX'], $expected['USDT']) ? ['TRX' => $expected['TRX']['rate'], 'USD' => $expected['USDT']['rate']] : null;
if ($rates !== $expectedCheckout) {
    throw new RuntimeException('Unexpected checkout rates: ' . json_encode($rates));
}
echo "Market rates: $mode passed.\n";
