<?php

declare(strict_types=1);

use Faoxima\Services\Renewal\WalletRenewal;
use Faoxima\Services\Subscriptions\InvoiceDeletion;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
$dsn = getenv('FAOXIMA_TEST_DSN');
if (!$dsn) {
    throw new RuntimeException('Set FAOXIMA_TEST_DSN to a disposable MySQL/MariaDB database.');
}
$pdo = new PDO($dsn, getenv('FAOXIMA_TEST_DB_USER') ?: 'root', getenv('FAOXIMA_TEST_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

function check(bool $outcome, string $label): void
{
    if (!$outcome) {
        throw new RuntimeException($label);
    }
}

$pdo->exec("CREATE TEMPORARY TABLE invoice (id_invoice VARCHAR(40) PRIMARY KEY, id_user VARCHAR(40), username VARCHAR(100),
    Status VARCHAR(40), uuid TEXT, user_info TEXT)");
$pdo->exec('CREATE TEMPORARY TABLE Payment_report (id_user VARCHAR(40), id_invoice VARCHAR(100))');
$pdo->exec("CREATE TEMPORARY TABLE service_other (id INT AUTO_INCREMENT PRIMARY KEY, id_user VARCHAR(40), username VARCHAR(100),
    value VARCHAR(1000), type VARCHAR(30), time VARCHAR(30), price DECIMAL(12,2), output TEXT, status VARCHAR(30),
    CONSTRAINT history_write_blocked CHECK (type <> 'extend_user'))");
$pdo->exec('CREATE TEMPORARY TABLE renewal_operation (ref VARCHAR(40), id_invoice VARCHAR(40), username VARCHAR(100), source VARCHAR(20), state VARCHAR(20))');
$pdo->exec("INSERT INTO invoice VALUES
    ('active', '10', 'one', 'active', NULL, NULL),
    ('draft', '10', 'two', 'Unpaid', NULL, NULL),
    ('pay', '10', 'three', 'unpaid', NULL, NULL),
    ('remote', '10', 'four', 'unpaid', 'remote-uuid', NULL),
    ('wallet', '10', 'five', 'end_of_time', NULL, NULL)");
$pdo->exec("INSERT INTO Payment_report VALUES ('10', 'getconfigafterpay|three')");
check(InvoiceDeletion::selected($pdo, ['active', 'draft', 'pay', 'remote']) === 1, 'Only the unprovisioned, unlinked draft may be deleted');
check($pdo->query('SELECT id_invoice FROM invoice ORDER BY id_invoice')->fetchAll(PDO::FETCH_COLUMN) === ['active', 'pay', 'remote', 'wallet'], 'Active and protected orders remain');

$reference = str_repeat('a', 32);
$order = ['operation_id' => $reference, 'invoice_id' => 'wallet', 'wallet_state' => 'charged'];
$stmt = $pdo->prepare("INSERT INTO service_other VALUES (1, '10', 'five', ?, 'wallet_renewal', '2026/01/01', 12345, ?, 'pending')");
$stmt->execute([json_encode($order), json_encode(['history' => ['code_product' => 'plan-a', 'Service_time' => 30]])]);
$funding = ['id' => 1, 'order' => $order, 'price' => 12345.0];
check(WalletRenewal::claim($pdo, $funding), 'Reserve may be claimed once');
check(!WalletRenewal::claim($pdo, $funding), 'A repeated claim is rejected');
try {
    WalletRenewal::result($pdo, $funding, 'applied', true, ['status' => true, 'operation_id' => $reference]);
    throw new RuntimeException('The injected history constraint did not fail');
} catch (PDOException $expected) {
    check(str_contains($expected->getMessage(), 'history_write_blocked'), 'History failure was injected intentionally');
}
$pending = $pdo->query('SELECT * FROM service_other WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
check($pending['status'] === 'pending', 'Invoice history failure rolls back completion');
check($pdo->query("SELECT Status FROM invoice WHERE id_invoice = 'wallet'")->fetchColumn() === 'end_of_time', 'Invoice activation did not escape the transaction');
$stmt = $pdo->prepare("INSERT INTO renewal_operation VALUES (?, 'wallet', 'five', 'miniapp', 'verified')");
$stmt->execute([$reference]);
$pdo->exec('ALTER TABLE service_other DROP CONSTRAINT history_write_blocked');
check(WalletRenewal::recover($pdo, $pending), 'Cron recovery finalizes a verified renewal');
check(!WalletRenewal::recover($pdo, $pending), 'Repeating recovery does not repeat completion');
$history = $pdo->query("SELECT value, price FROM service_other WHERE type = 'extend_user'")->fetchAll(PDO::FETCH_ASSOC);
check(count($history) === 1, 'Exactly one paid history row exists');
$value = json_decode($history[0]['value'], true);
check($value['invoice_id'] === 'wallet' && $value['id_order'] === $reference && (float) $history[0]['price'] === 12345.0,
    'History is tied to the original subscription, operation and actual wallet charge');
check($pdo->query("SELECT Status FROM invoice WHERE id_invoice = 'wallet'")->fetchColumn() === 'active', 'Verified operation activates the subscription');
echo "Subscription lifecycle checks passed.\n";
