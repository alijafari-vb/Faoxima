<?php

declare(strict_types=1);

use Faoxima\Admin\Users\UserSearch;
use Faoxima\Services\Subscriptions\PlanLabel;
use Faoxima\Services\Subscriptions\ServiceSearch;
use Faoxima\Services\Subscriptions\SubscriptionTable;

// Start the panel fixture in a separate terminal:
// php -S 127.0.0.1:43497 scripts/tests/service-search.php
// Then run against a disposable MariaDB/MySQL database with CREATE TEMPORARY TABLE privileges:
// FAOXIMA_TEST_DSN='mysql:host=127.0.0.1;dbname=faoxima_test;charset=utf8mb4' php scripts/tests/service-search.php
// FAOXIMA_TEST_DB_USER and FAOXIMA_TEST_DB_PASSWORD override the connection credentials.

if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (in_array($path, ['/legacy/sub/fixture-token/info', '/wrong/fixture-token/info'], true)) {
        echo '{"username":"external_username"}';
        return;
    }
    if ($path === '/xui-legacy/login' && ($_POST['username'] ?? '') === 'fixture-admin' && ($_POST['password'] ?? '') === 'fixture-password') {
        setcookie('session', 'fixture-session', 0, '/xui-legacy');
        echo '{"success":true}';
        return;
    }
    if ($path === '/xui-legacy/panel/api/inbounds/onlines' && ($_COOKIE['session'] ?? '') === 'fixture-session') {
        echo '{"success":true,"obj":["legacy-account"]}';
        return;
    }
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-api-key') {
        http_response_code(401);
        echo '{}';
        return;
    }
    if ($path === '/xui-token/panel/api/clients/onlines') {
        echo '{"success":true,"obj":["same-name"]}';
        return;
    }
    $panel = explode('/', $path)[1];
    if ($path === "/$panel/api/users/onlines") {
        echo $panel === 'east' ? '["same-name","deleted"]' : '[]';
        return;
    }
    $base = 'https://' . $panel . '.subscriptions.test' . ($panel === 'west' ? '/west' : '') . '/sub';
    $key = $panel === 'east' ? 'aaaabbbbccccddddeeeeffff00001111' : 'aaaabbbbccccddddeeeeffff00002222';
    $user = [
        'id' => $panel === 'legacy-id' ? 11 : 91, 'username' => 'same-name',
        'status' => 'active', 'expire' => 2000000000, 'data_limit' => 500, 'used_traffic' => 250,
        'subscription_url' => $base . '/same-name/' . $key,
        'subscription_urls' => [
            'username-key' => $base . '/same-name/' . $key,
            'key' => $base . '/' . $key,
            'subadress' => $base . '/legacy-address-' . $panel,
            'token' => $base . '/new-rebecca-token-signed',
        ],
        'links' => ['vless://' . ($panel === 'east' ? '11111111-2222-4333-8444-555555555555' : '99999999-8888-4777-8666-555555555555') . '@nodes.test:443#Label'],
    ];
    if ($path === "/$panel/sub/old-rebecca-token-signed/info") {
        echo json_encode(['user' => ['username' => $user['username']]]);
        return;
    }
    if ($path === "/$panel/api/user/same-name") {
        unset($user['subscription_urls']);
        echo json_encode($user + ['status' => 'active']);
        return;
    }
    if ($path !== "/$panel/api/users") {
        http_response_code(404);
        echo '{}';
        return;
    }
    $query = $_GET['search'] ?? null;
    if ($query !== null && $query === '91' && $panel === 'east' && (int)($_GET['offset'] ?? 0) === 0) {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['id' => $i + 100, 'username' => 'wrong_' . $i];
        }
        echo json_encode(['total' => 51, 'users' => $rows]);
        return;
    }
    if ($query !== null && !in_array(strtolower($query), [
        '91', 'old-rebecca-token-signed', 'wrong-token-in-note', 'legacy-address-' . $panel, $key,
        '11111111-2222-4333-8444-555555555555', '99999999-8888-4777-8666-555555555555',
    ], true)) {
        echo '{"total":0,"users":[]}';
        return;
    }
    if ($query === null && !str_contains($_SERVER['QUERY_STRING'], 'username=same-name')) {
        echo '{"total":0,"users":[]}';
        return;
    }
    echo json_encode(['total' => $query === '91' && $panel === 'east' ? 51 : 1, 'users' => [$user]]);
    return;
}
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$testDsn = getenv('FAOXIMA_TEST_DSN');
if ($testDsn === false || $testDsn === '') {
    fwrite(STDERR, "Set FAOXIMA_TEST_DSN to a disposable MariaDB or MySQL database.\n");
    exit(1);
}
require_once dirname(__DIR__, 2) . '/panels.php';
restore_exception_handler();

$pdo = new PDO($testDsn, getenv('FAOXIMA_TEST_DB_USER') ?: 'root', getenv('FAOXIMA_TEST_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$GLOBALS['pdo'] = $pdo;
$GLOBALS['domainhosts'] = 'shop.example.test';
$GLOBALS['setting'] = ['panel_curl_timeout' => 5];
$pdo->exec("CREATE TEMPORARY TABLE setting (panel_curl_timeout int, redis_enabled varchar(10))");
$pdo->exec("INSERT INTO setting VALUES (5, 'off')");
$pdo->exec("CREATE TEMPORARY TABLE marzban_panel (id int, name_panel varchar(100), type varchar(30), url_panel varchar(250), api_key varchar(50))");
$pdo->exec("INSERT INTO marzban_panel VALUES
  (1, 'east', 'rebecca', 'http://127.0.0.1:43497/east', 'fixture-api-key'),
  (2, 'west', 'rebecca', 'http://127.0.0.1:43497/west', 'fixture-api-key'),
  (3, 'manual', 'Manualsale', '', '')");
$pdo->exec("ALTER TABLE marzban_panel ADD subvip varchar(10) DEFAULT 'onsubvip'");
$pdo->exec("CREATE TEMPORARY TABLE invoice (id_invoice varchar(40) PRIMARY KEY, id_user varchar(30), username varchar(100), Service_location varchar(100), Status varchar(20), note varchar(255), Volume varchar(20), uuid text, user_info text)");
$rows = [
    ['e123', '100', 'same-name', 'east', 'active', 'match 91', '5', null, '{}'],
    ['w123', '200', 'same-name', 'west', 'disabled', null, '5', null, 'malformed JSON'],
    ['z123', '100', 'deleted', 'east', 'removebyuser', null, '5', null, null],
    ['m123', '100', 'literal_%', 'manual', 'active', 'match 91', '91', null, 'https://manual.test/sub/token?a=1&b=2'],
    ['x123', '200', 'literalXX', 'manual', 'active', null, '5', null, null],
    ['l123', '100', 'cache_user', 'manual', 'active', null, '7', '{"vless":{"id":"abcd"}}', '{"uuid":"76543210-1111-4222-8333-123456789abc","subscription_url":"https://cache.test/sub/old"}'],
];
$insert = $pdo->prepare('INSERT INTO invoice VALUES (?,?,?,?,?,?,?,?,?)');
foreach ($rows as $row) {
    $insert->execute($row);
}

$tested = 0;
function same($expected, $actual, string $label): void
{
    global $tested;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': ' . json_encode(compact('expected', 'actual')));
    }
    $tested++;
}

function search(PDO $pdo, string $query, ?string $owner): array
{
    $scope = $owner === null ? '1=1' : 'id_user = :owner AND ' . ServiceSearch::VISIBLE_STATUS;
    $params = $owner === null ? [] : [':owner' => $owner];
    [$where, $extra] = ServiceSearch::condition($pdo, $query, $scope, $params, ['username', 'note', 'Volume']);
    $stmt = $pdo->prepare("SELECT id_invoice FROM invoice WHERE $scope AND $where ORDER BY id_invoice");
    $stmt->execute($params + $extra);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$pdo->exec('CREATE TEMPORARY TABLE textbot (id_text varchar(150), text text)');
same('پیش‌فرض 20 گیگ 60 روز', PlanLabel::display(['Volume_constraint' => '20', 'Service_time' => '60']), 'standard plans are named from their limits');
same(['20 گیگ', '60 روز'], PlanLabel::terms(['name_product' => 'پیش‌فرض فروشگاه', 'Volume_constraint' => '20', 'Service_time' => '60']), 'a priced Telegram offer uses separate volume and duration cells');
same('حجم نامحدود · زمان نامحدود', PlanLabel::offer(['Volume_constraint' => 0, 'Service_time' => 0]), 'a compact offer keeps both unlimited terms clear');
same('1.5 گیگ · زمان نامحدود', PlanLabel::offer(['name_product' => 'پلن الف', 'Volume_constraint' => '1.50', 'Service_time' => 0]), 'a compact offer keeps decimal volume and omits the stored product name');
same('پیش‌فرض 20 گیگ نامحدود', PlanLabel::display(['Volume' => 20, 'Service_time' => 0]), 'unlimited time stays explicit');
same('پیش‌فرض نامحدود 30 روز', PlanLabel::display(['Volume' => 0, 'Service_time' => 30]), 'unlimited traffic stays explicit');
same('دلخواه نامحدود', PlanLabel::display(['Volume' => 0, 'Service_time' => 0, 'name_product' => '⚙️ سرویس دلخواه']), 'custom plans keep their prefix with a single unlimited label');
same('دلخواه 20 گیگ 30 روز', PlanLabel::display(['Volume' => 20, 'Service_time' => 30, 'pricing_snapshot' => '{"product":"customvolume"}']), 'the stored product code also identifies custom invoices');
same('تست 250 مگ 4 ساعت', PlanLabel::display(['Volume' => 250, 'Service_time' => 4, 'name_product' => 'سرویس تست']), 'test invoices use the original hours and MB units');
same(null, PlanLabel::display(['Service_time' => '60']), 'incomplete legacy plans keep their existing label');
$pdo->exec("CREATE TEMPORARY TABLE user (id varchar(30) PRIMARY KEY, username varchar(100))");
$pdo->exec("INSERT INTO user VALUES ('100','BuyerName'), ('200','sameHandle'), ('300','SameHandle'), ('400','NOT_USERNAME')");
same('100', UserSearch::telegramId($pdo, '@buyername'), 'Telegram username lookup is exact and case insensitive');
same('100', UserSearch::telegramId($pdo, 'https://t.me/BuyerName'), 'official Telegram profile links resolve as exact usernames');
same(null, UserSearch::telegramId($pdo, '@buyer'), 'a partial handle cannot open an unrelated user');
same(null, UserSearch::telegramId($pdo, '@sameHandle'), 'ambiguous stored Telegram usernames require a numeric ID');
same(null, UserSearch::telegramId($pdo, '@NOT_USERNAME'), 'the no-username marker is not a Telegram account');
same('100', UserSearch::telegramId($pdo, '۱۰۰'), 'Telegram numeric IDs accept Persian digits');
same('200', UserSearch::telegramId($pdo, '@BuyerName', ['forward_origin' => ['type' => 'user', 'sender_user' => ['id' => 200]]]), 'forwarded identity comes from Telegram metadata, not message contents');
same('300', UserSearch::telegramId($pdo, '', ['forward_from' => ['id' => 300]]), 'legacy Telegram forwards work');
same(null, UserSearch::telegramId($pdo, '@BuyerName', ['forward_origin' => ['type' => 'hidden_user', 'sender_user_name' => 'BuyerName']]), 'a hidden forward cannot resolve another user from its text');

$pdo->exec('ALTER TABLE user ADD namecustom varchar(100), ADD number varchar(30), ADD number_username varchar(30)');
$pdo->exec("INSERT INTO user VALUES ('11', 'owner11', NULL, '09123456789', NULL), ('111', 'BuyerName11', 'BuyerName', '09123456780', NULL)");
foreach ([
    '۱۱' => ['11'], 'buyername' => ['100'], 'https://t.me/BuyerName' => ['100'], '@buyer' => [],
    '+98 912 345 6789' => ['11'], '789' => [], 'BuyerName%' => [],
] as $query => $ids) {
    [$where, $params] = UserSearch::condition($pdo, (string) $query);
    $stmt = $pdo->prepare("SELECT id FROM user WHERE $where ORDER BY id");
    $stmt->execute($params);
    same($ids, $stmt->fetchAll(PDO::FETCH_COLUMN), 'web user search: ' . $query);
}
same(['e123'], search($pdo, '۹۱', '100'), 'Persian numeric ID / buyer');
same(['w123'], search($pdo, '#٩١', '200'), 'Arabic digits / second owner');
same(['e123', 'w123'], search($pdo, '91', null), 'same numeric ID on two panels / administrator');
$insert->execute(['91', '100', 'sample-911', 'manual', 'active', 'same-name 91 11', '91', null, '{}']);
$insert->execute(['lookup-external-11', '100', '11', 'manual', 'active', 'same-name', '11', null, '{}']);
same(['e123', 'w123'], search($pdo, '91', null), 'numeric matches never include similarly named subscriptions or a matching invoice number');
same(['e123'], search($pdo, '#۹۱', '100'), 'numeric matching observes the customer scope and Persian digits');
same([], search($pdo, '11', null), 'missing panel IDs do not match an incidental number or numeric username');
same(['lookup-external-11'], search($pdo, '@11', '100'), 'an explicitly supplied numeric username is matched exactly');
same(['91'], search($pdo, 'order:۹۱', '100'), 'an explicitly supplied order number is distinct from a subscription ID');
same([], search($pdo, 'order:91', '200'), 'exact order lookup observes ownership');
same(['e123'], search($pdo, 'same-name', '100'), 'the exact name takes priority over notes');
$pdo->exec("INSERT INTO marzban_panel (id, name_panel, type, url_panel, api_key) VALUES
    (4, 'legacy-id', 'rebecca', 'http://127.0.0.1:43497/legacy-id', 'fixture-api-key')");
$insert->execute(['actual-id-11', '100', 'same-name', 'legacy-id', 'active', null, '5', null, '{}']);
same(['actual-id-11'], search($pdo, '11', null), 'a legacy panel without ID search still resolves the exact numeric match by scoped username');
same([], search($pdo, '11', '200'), 'compatibility lookup only searches panels from the caller scope');
$pdo->exec("DELETE FROM invoice WHERE id_invoice = 'actual-id-11'");
$pdo->exec("DELETE FROM marzban_panel WHERE name_panel = 'legacy-id'");
$pdo->exec("DELETE FROM invoice WHERE id_invoice IN ('91','lookup-external-11')");
same(['m123'], search($pdo, 'literal_%', '100'), 'literal SQL LIKE metacharacters');
same(['e123'], search($pdo, 'same-name', '100'), 'partial username under owner');
same([], search($pdo, 'match', '300'), 'no search across other owners');
same([], search($pdo, 'deleted', '100'), 'deleted record hidden');
same(['z123'], search($pdo, 'deleted', null), 'order archive for administrator');
same(['e123'], search($pdo, '11111111-2222-4333-8444-555555555555', '100'), 'VLESS UUID');
same([], search($pdo, '11111111-2222-4333-8444-555555555555', '200'), 'UUID never transfers to same username in another panel');
same(['e123'], search($pdo, 'AAAABBBBCCCCDDDDEEEEFFFF00001111', '100'), 'compact uppercase credential UUID');
same(['e123'], search($pdo, 'https://east.subscriptions.test/sub/same-name/aaaabbbbccccddddeeeeffff00001111#label', '100'), 'Rebecca username-key link');
same(['e123'], search($pdo, 'https://east.subscriptions.test/sub/legacy-address-east', '100'), 'legacy Rebecca subscription');
same(['e123'], search($pdo, 'https://east.subscriptions.test/sub/old-rebecca-token-signed', '100'), 'signed subscription token');
same(['w123'], search($pdo, 'https://west.subscriptions.test/west/sub/old-rebecca-token-signed', '200'), 'signed subscription behind a panel path prefix');
same(['e123'], search($pdo, 'https://east.subscriptions.test/sub/same-name/aaaabbbbccccddddeeeeffff00001111/clash-meta', '100'), 'Rebecca subscription with a client format');
same(['w123'], search($pdo, 'https://west.subscriptions.test/west/sub/old-rebecca-token-signed/sing_box', '200'), 'signed subscription with a client format');
same([], search($pdo, 'https://foreign.test/sub/old-rebecca-token-signed/clash-meta', '100'), 'a client suffix does not bypass subscription host matching');
same([], search($pdo, 'https://east.subscriptions.test/sub/wrong-token-in-note', '100'), 'fuzzy candidate does not validate random subscription');
same([], search($pdo, 'https://foreign.test/sub/old-rebecca-token-signed', '100'), 'foreign subscription host');
same(['e123'], search($pdo, 'https://shop.example.test/sub/e123?format=clash', '100'), 'Faoxima public subscription');
same([], search($pdo, 'https://shop.example.test/sub/w123', '100'), 'public subscription with another owner');
same(['m123'], search($pdo, 'https://manual.test/sub/token?a=1&b=2', '100'), 'stored plain URL with query string');
same(['l123'], search($pdo, 'https://cache.test/sub/old', '100'), 'stored JSON with malformed legacy row in scope');
same(['l123'], search($pdo, '76543210-1111-4222-8333-123456789abc', '100'), 'stored UUID');
same([], search($pdo, 'https://shop.example.test/sub/%65%31%32%33', '100'), 'partial or encoded local token does not widen lookup');
same([], search($pdo, 'https://east.subscriptions.test/sub/same-name/not-its-key', '100'), 'native username alone does not validate a link');
same([], search($pdo, str_repeat('a', 2049), null), 'oversized input');
$rows = ServiceSearch::addPanelIds($pdo, $pdo->query('SELECT * FROM invoice ORDER BY id_invoice')->fetchAll(PDO::FETCH_ASSOC));
same(['91', null, null, '91', null, null], array_column($rows, 'panel_user_id'), 'batch numeric enrichment keyed by panel and username');
$tableRows = ServiceSearch::addPanelIds($pdo, [$rows[0], $rows[1]], includeUsage: true);
same(['status' => 'active', 'expire' => 2000000000, 'data_limit' => 500, 'used_traffic' => 250], $tableRows[0]['panel_usage'], 'table uses Rebecca usage data without copying its subscription links');

same(
    ['—', '∞', '0B', '512B', '0.5GB', '21GB', '1.2TB', '1TB'],
    array_map([SubscriptionTable::class, 'formatTraffic'], [null, -1, 0, 512, 512 * 1024 ** 2, 21 * 1024 ** 3, 1319413953331, 1024 ** 4 - 1]),
    'Telegram traffic cells stay short without changing stored usage values'
);
$oneGigabyte = 1024 ** 3;
$baseRow = ['id_invoice' => 'e123', 'username' => 'same-name', 'panel_user_id' => '91', 'Status' => 'active'];
$warningRows = array_map(static fn(array $usage): array => $baseRow + $usage, [
    ['days_remaining' => 4, 'remaining_bytes' => $oneGigabyte],
    ['days_remaining' => 5, 'remaining_bytes' => $oneGigabyte - 1],
    ['days_remaining' => 0, 'remaining_bytes' => 0],
    ['days_remaining' => -1, 'remaining_bytes' => -1],
    ['days_remaining' => null, 'remaining_bytes' => null],
]);
$table = SubscriptionTable::buttons($warningRows, 'days_remaining', 'a', 'svctable', 'quickview_');
same(
    [[null, null, 'danger'], [null, 'danger', null], [null, 'danger', 'danger'], [null, null, null], [null, null, null]],
    array_map(static fn(array $row): array => array_map(static fn(array $button): ?string => $button['style'] ?? null, $row), array_slice($table, 1)),
    'only the relevant finite days or traffic cells turn red below their thresholds'
);
same(['🟢 same-name (91)', '1GB', '4'], array_column($table[1], 'text'), 'warnings preserve short labels and username space');
same(['<1GB', 'تمام', '∞', '—'], array_column(array_column(array_slice($table, 2), 1), 'text'), 'traffic distinguishes near-threshold, depleted, unlimited, and unknown values');
same(['منقضی', '∞', '—'], array_column(array_column(array_slice($table, 3), 2), 'text'), 'days distinguish expired, unlimited, and unknown values');
same(array_fill(0, 3, 'quickview_e123'), array_column($table[1], 'callback_data'), 'warning cells still open subscription details');
$renewalTable = SubscriptionTable::buttons($warningRows, 'days_remaining', 'a', 'renewtable', 'extend_');
same(SubscriptionTable::button('روز مانده ↑', 'renewtable:days_remaining:d:1'), $renewalTable[0][2], 'renewal uses the same headers with its own reversible sorting');
require_once dirname(__DIR__, 2) . '/botapi.php';
foreach (['myst' => 'quickview_', 'renewt' => 'extend_', 'sadm:all' => 'manageinvoice_', 'sadm:online' => 'manageinvoice_'] as $prefix => $details) {
    $buttons = SubscriptionTable::buttons([$warningRows[0]], 'recent', 'd', $prefix, $details);
    $buttons[] = [SubscriptionTable::button('بعدی', "$prefix:recent:d:2"), SubscriptionTable::button('بازگشت', 'backorder', 'danger')];
    $markup = processKeyboardStyles(['inline_keyboard' => $buttons], str_starts_with($prefix, 'sadm'));
    same([['primary', 'primary', 'primary'], [null, null, 'danger'], ['primary', 'danger']],
        array_map(static fn(array $row): array => array_map(static fn(array $btn): ?string => $btn['style'] ?? null, $row), $markup['inline_keyboard']),
        'normal Telegram rendering preserves common table styles for ' . $prefix);
}

$backCallback = 'admsub:online:remaining_bytes:a:2';
same('manageinvoice_e123:' . $backCallback, SubscriptionTable::adminDetails('e123', $backCallback), 'management detail callback retains its originating page');
same('manageinvoice_' . str_repeat('a', 40), SubscriptionTable::adminDetails(str_repeat('a', 40), $backCallback), 'long IDs fall back to the existing detail callback');

$pdo->exec("ALTER TABLE invoice ADD time_sell bigint DEFAULT 0");
$pdo->prepare('UPDATE invoice SET user_info = :info WHERE id_invoice = :id')
    ->execute([':id' => 'm123', ':info' => '{"expire":0,"data_limit":0,"used_traffic":0}']);
$scope = 'id_user = :owner AND ' . ServiceSearch::VISIBLE_STATUS;
$params = [':owner' => '100'];
$firstPage = ServiceSearch::invoicePage($pdo, $scope, $params, 2, 0, 'remaining_bytes');
$secondPage = ServiceSearch::invoicePage($pdo, $scope, $params, 2, 2, 'remaining_bytes');
$ascending = ServiceSearch::invoicePage($pdo, $scope, $params, 3, 0, 'remaining_bytes', true);
same(['m123', 'e123'], array_column($firstPage, 'id_invoice'), 'table sorts all owned results before taking the first page');
same(['l123'], array_column($secondPage, 'id_invoice'), 'table keeps missing usage last and excludes other owners and removed accounts');
same(['e123', 'm123', 'l123'], array_column($ascending, 'id_invoice'), 'table reverses finite and unlimited usage but keeps unknown values last');
$pdo->exec("INSERT INTO invoice (id_invoice, id_user, username, Service_location, Status, time_sell)
    VALUES ('later-order', '100', 'same-name', 'east', 'active', 2)");
$panel = new ManagePanel();
$selected = $panel->DataUser('east', 'same-name', 'links', 'e123');
same('https://shop.example.test/sub/e123', $selected['subscription_url'] ?? null, 'selected order has its own Faoxima subscription link');
$legacy = $panel->DataUser('east', 'same-name');
same('https://shop.example.test/sub/later-order', $legacy['subscription_url'] ?? null, 'legacy callers resolve the latest order in their panel');
$pdo->exec("UPDATE invoice SET Status = 'active' WHERE id_invoice = 'w123'");
$pdo->exec("ALTER TABLE marzban_panel ADD code_panel varchar(20), ADD xui_api_mode varchar(20), ADD xui_api_token varchar(50), ADD username_panel varchar(50), ADD password_panel varchar(50)");
$pdo->exec("UPDATE marzban_panel SET code_panel = name_panel");
$legacyPanel = ['type' => 'marzban', 'url_panel' => 'http://127.0.0.1:43497/legacy'];
same('external_username', ServiceSearch::externalPanelUsername($legacyPanel, 'http://127.0.0.1:43497/legacy/sub/fixture-token'), 'external legacy links use their configured panel subscription URL');
same(null, ServiceSearch::externalPanelUsername($legacyPanel, 'http://127.0.0.1:43497/wrong/fixture-token'), 'links outside the configured subscription path are not resolved');
$pdo->exec("INSERT INTO marzban_panel (id, name_panel, type, url_panel, code_panel, xui_api_mode, xui_api_token, username_panel, password_panel) VALUES
    (4, 'xui-token', 'x-ui_single', 'http://127.0.0.1:43497/xui-token', 'xui-token', 'token', 'fixture-api-key', NULL, NULL),
    (5, 'xui-legacy', 'x-ui_single', 'http://127.0.0.1:43497/xui-legacy', 'xui-legacy', 'legacy', NULL, 'fixture-admin', 'fixture-password'),
    (6, 'xui-failed', 'x-ui_single', 'http://127.0.0.1:43497/xui-failed', 'xui-failed', 'token', 'fixture-api-key', NULL, NULL)");
$pdo->exec("INSERT INTO invoice (id_invoice, id_user, username, Service_location, Status, time_sell) VALUES
    ('token-online', '100', 'same-name', 'xui-token', 'active', 0),
    ('legacy-online', '100', 'legacy-account', 'xui-legacy', 'active', 0),
    ('failed-panel', '100', 'same-name', 'xui-failed', 'active', 0),
    ('disabled-xui', '100', 'same-name', 'xui-token', 'disabled', 0)");
[$onlineScope, $onlineParams, $unavailable] = ServiceSearch::onlineScope($pdo);
$online = ServiceSearch::invoicePage($pdo, $onlineScope, $onlineParams, 10, 0, 'username', true, liveUsage: false);
same(2, $unavailable, 'unsupported and failed panels are reported separately from online matches');
same(['legacy-online', 'later-order', 'token-online', 'e123'], array_column($online, 'id_invoice'), 'Rebecca and both 3x-ui modes match exactly by panel and username');
same([null, null, null, null], array_column($online, 'remaining_bytes'), 'global list sorting uses cached quota and does not fan out for live usage');
$pdo->exec("INSERT INTO invoice (id_invoice, username, Service_location, Status, user_info) VALUES
    ('usage-hours', 'hours', 'manual', 'active', '{\"expire\":" . (time() + 1800) . ",\"data_limit\":1024,\"used_traffic\":1025}'),
    ('usage-expired', 'expired', 'manual', 'end_of_time', NULL),
    ('usage-limited', 'limited', 'manual', 'end_of_volume', NULL),
    ('usage-hold', 'hold', 'manual', 'send_on_hold', '{\"expire\":0,\"data_limit\":0}')");
$usage = ServiceSearch::invoicePage($pdo, "id_invoice LIKE 'usage-%'", [], 4, 0, 'username', true, liveUsage: false);
same([0, null, 1, null], array_column($usage, 'days_remaining'), 'expired, on hold, below one day, and unknown dates remain distinct');
same([null, -1, 0, 0], array_column($usage, 'remaining_bytes'), 'depleted and overspent traffic resolve to zero, while unlimited is distinct');
$cached = ServiceSearch::invoicePage($pdo, "id_invoice LIKE 'usage-%'", [], 4, 0, 'remaining_bytes', true, liveUsage: false);
same(['usage-limited', 'usage-hours', 'usage-hold', 'usage-expired'], array_column($cached, 'id_invoice'),
    'cached metric sort happens in the database before pagination');
echo "$tested service search checks passed.\n";
