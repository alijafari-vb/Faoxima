<?php

namespace Faoxima\Services\Subscriptions;

use PDO;

final class InvoiceDeletion
{
    public const RESTRICTION = 'حذف فقط برای سفارش پرداخت‌نشده‌ای ممکن است که اشتراک یا سابقهٔ پرداخت ندارد.';

    public static function eligible(): string
    {
        return "LOWER(invoice.Status) = 'unpaid'
            AND COALESCE(invoice.uuid, '') = ''
            AND COALESCE(TRIM(invoice.user_info), '') IN ('', '{}', 'null')
            AND NOT EXISTS (SELECT 1 FROM Payment_report p WHERE p.id_invoice = invoice.id_invoice
                OR (p.id_user = invoice.id_user AND COALESCE(invoice.username, '') <> ''
                    AND p.id_invoice = CONCAT('getconfigafterpay|', invoice.username)))
            AND NOT EXISTS (SELECT 1 FROM service_other o WHERE o.id_user = invoice.id_user
                AND o.username = invoice.username AND o.status IN ('paid', 'pending', 'done'))";
    }

    public static function selected(PDO $pdo, array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($id) => is_string($id) && $id !== '' && strlen($id) <= 200)));
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM invoice WHERE id_invoice IN ($marks) AND " . self::eligible());
        $stmt->execute($ids);
        return $stmt->rowCount();
    }
}
