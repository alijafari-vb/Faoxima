<?php

namespace Faoxima\Services\Renewal;

use Faoxima\Services\Fulfillment\WalletExtensions;
use Faoxima\Payments\Fulfillment\Store;
use PDO;

final class WalletRenewal
{
    public static function reserve(PDO $pdo, array $user, array $invoice, string $reference, float $price, string $discount, string $note, ?array $history = null): array
    {
        $invoiceId = (string) $invoice['id_invoice'];
        $order = ['operation_id' => $reference, 'invoice_id' => $invoiceId, 'ledger_invoice' => $invoiceId,
            'discount_code' => $discount, 'wallet_state' => 'charged'];
        $result = WalletExtensions::reserve($pdo, $user, $invoice['username'], $order, 'wallet_renewal',
            $price, $user['agent'] ?? 'f', $note, $history === null ? [] : ['history' => $history]);
        return $result + ['order' => $order, 'price' => $price];
    }

    public static function claim(PDO $pdo, array $funding): bool
    {
        return self::write($pdo, $funding, 'charged', 'dispatching');
    }

    public static function result(PDO $pdo, array $funding, string $outcome, bool $activate = true, array $providerResult = []): bool
    {
        if ($outcome === 'applied') {
            return self::complete($pdo, $funding, 'dispatching', $activate, $providerResult);
        }
        return self::write($pdo, $funding, 'dispatching', $outcome === 'pending' ? 'uncertain' : $outcome);
    }

    public static function refund(PDO $pdo, array $funding, string $user, string $note): void
    {
        WalletExtensions::refund($pdo, $funding['id'], ['id' => $user], $funding['order'], $funding['price'],
            ['status' => false, 'no_mutation' => true], $note);
    }

    public static function recover(PDO $pdo, array $row): bool
    {
        $order = json_decode((string) $row['value'], true);
        if (!is_array($order) || empty($order['operation_id'])) {
            return false;
        }
        $funding = ['id' => (int) $row['id'], 'order' => $order, 'price' => (float) $row['price']];
        $state = $order['wallet_state'] ?? '';
        if ($state === 'charged' || $state === 'refundable') {
            self::refund($pdo, $funding, (string) $row['id_user'], 'بازگشت وجه تمدید اشتراک');
            return true;
        }
        if (!in_array($state, ['dispatching', 'uncertain'], true)) {
            return false;
        }
        $proof = $pdo->prepare("SELECT 1 FROM renewal_operation WHERE ref = ? AND id_invoice = ? AND username = ?
            AND source IN ('wallet', 'miniapp') AND state = 'verified' LIMIT 1");
        $proof->execute([$order['operation_id'], $order['invoice_id'], $row['username']]);
        if ($proof->fetchColumn() !== false) {
            return self::complete($pdo, $funding, $state, true, ['status' => true, 'operation_id' => $order['operation_id']]);
        }
        $queued = $pdo->prepare('SELECT 1 FROM queued_renewal WHERE payment_ref = ? AND id_invoice = ? AND id_user = ? AND username = ? LIMIT 1');
        $queued->execute([$order['operation_id'], $order['invoice_id'], $row['id_user'], $row['username']]);
        return $queued->fetchColumn() !== false && self::complete($pdo, $funding, $state, false, ['status' => true, 'queued' => true, 'operation_id' => $order['operation_id']]);
    }

    private static function complete(PDO $pdo, array $funding, string $state, bool $activate, array $providerResult): bool
    {
        return Store::tx($pdo, static function (PDO $pdo) use ($funding, $state, $activate, $providerResult) {
            if (!self::write($pdo, $funding, $state, 'applied', 'done')) {
                return false;
            }
            $source = $pdo->prepare('SELECT id_user, username, time, output FROM service_other WHERE id = ?');
            $source->execute([$funding['id']]);
            $original = $source->fetch(PDO::FETCH_ASSOC);
            $saved = json_decode((string) ($original['output'] ?? ''), true);
            if (isset($saved['history']) && is_array($saved['history'])) {
                $history = $saved['history'];
                $before = $providerResult['rx_renewal']['before'] ?? [];
                if (isset($before['data_limit'], $before['expire'])) {
                    $history['oldvolume'] = $before['data_limit'];
                    $history['oldtime'] = $before['expire'];
                }
                $history['id_order'] = $funding['order']['operation_id'];
                $history['invoice_id'] = $funding['order']['invoice_id'];
                $value = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (mb_strlen($value, 'UTF-8') > 1000) {
                    unset($history['pricing_snapshot'], $history['reservation']);
                    $value = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
                $record = $pdo->prepare("INSERT INTO service_other (id_user, username, value, type, time, price, output, status)
                    VALUES (?, ?, ?, 'extend_user', ?, ?, ?, 'paid')");
                $record->execute([$original['id_user'], $original['username'], $value, $original['time'], $funding['price'],
                    json_encode($providerResult, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
            if ($activate) {
                $statement = $pdo->prepare("UPDATE invoice i JOIN service_other o ON o.id = ? AND o.id_user = i.id_user
                    AND o.username = i.username SET i.Status = 'active' WHERE i.id_invoice = ?
                    AND i.Status IN ('end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')");
                $statement->execute([$funding['id'], $funding['order']['invoice_id']]);
            }
            return true;
        });
    }

    private static function write(PDO $pdo, array $funding, string $from, string $to, string $status = 'pending'): bool
    {
        $encode = static fn (string $state) => json_encode(['wallet_state' => $state] + $funding['order'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $expected = $from === 'charged' ? json_encode($funding['order'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $encode($from);
        $stmt = $pdo->prepare("UPDATE service_other SET value = ?, status = ? WHERE id = ?
            AND type = 'wallet_renewal' AND status = 'pending' AND value = ?");
        return $stmt->execute([$encode($to), $status, $funding['id'], $expected]) && $stmt->rowCount() === 1;
    }
}
