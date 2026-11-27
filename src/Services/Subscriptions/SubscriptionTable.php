<?php

namespace Faoxima\Services\Subscriptions;

final class SubscriptionTable
{
    public static function formatTraffic(?int $bytes): string
    {
        if ($bytes === null || $bytes === -1 || $bytes === 0) {
            return match ($bytes) { null => '—', -1 => '∞', 0 => '0B' };
        }
        $unit = (int) floor(log($bytes, 1024));
        if ($bytes >= (1024 ** 3) / 10) {
            $unit = max(3, $unit);
        }
        $size = round($bytes / (1024 ** $unit), 1);
        if ($unit === 3 && $size === 1.0 && $bytes < 1024 ** 3) {
            return '<1GB';
        }
        if ($size === 1024.0) {
            $size = 1;
            $unit++;
        }
        return $size . ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'][$unit];
    }

    public static function button(string $text, string $callback, string $style = 'primary'): array
    {
        return ['text' => $text, 'callback_data' => $callback, 'style' => $style, '_rxkeep' => 1];
    }

    public static function buttons(array $rows, string $sort, string $direction, string $tablePrefix, string|callable $detailsCallback): array
    {
        if ($rows === []) {
            return [];
        }

        $headings = [];
        foreach (['username' => 'نام اشتراک', 'remaining_bytes' => 'حجم مانده', 'days_remaining' => 'روز مانده'] as $field => $label) {
            $nextDirection = $field === $sort ? ($direction === 'a' ? 'd' : 'a') : ($field === 'username' ? 'a' : 'd');
            $arrow = $field === $sort ? ($direction === 'a' ? ' ↑' : ' ↓') : '';
            $headings[] = self::button($label . $arrow, "$tablePrefix:$field:$nextDirection:1");
        }
        $buttons = [$headings];
        foreach ($rows as $row) {
            $status = match ($row['panel_usage']['status'] ?? $row['Status']) {
                'disabled', 'disablebyadmin' => '🔴',
                'end_of_time', 'expired' => '⌛',
                'end_of_volume', 'limited' => '📉',
                'send_on_hold', 'on_hold' => '⏸',
                'sendedwarn' => '⚠️',
                'active' => '🟢',
                default => '⚪',
            };
            $username = trim((string) $row['username']);
            $id = $row['panel_user_id'] ?? null;
            if ($id !== null) {
                $username .= ' (' . (strlen($id) > 5 ? '…' . substr($id, -5) : $id) . ')';
            }

            $days = $row['days_remaining'];
            $bytes = $row['remaining_bytes'];
            $daysLabel = match ($days) {
                null => '—', -1 => '∞', 0 => 'منقضی', default => $days > 999 ? '999+' : (string) $days,
            };
            $details = is_string($detailsCallback) ? $detailsCallback . $row['id_invoice'] : $detailsCallback($row);
            $normal = ['_rxap' => 1];
            $warning = ['style' => 'danger', '_rxkeep' => 1];
            $buttons[] = [
                ['text' => $status . ' ' . ($username !== '' ? $username : '—'), 'callback_data' => $details] + $normal,
                ['text' => $bytes === 0 ? 'تمام' : self::formatTraffic($bytes), 'callback_data' => $details]
                    + ($bytes !== null && $bytes >= 0 && $bytes < 1024 ** 3 ? $warning : $normal),
                ['text' => $daysLabel, 'callback_data' => $details]
                    + ($days !== null && $days >= 0 && $days < 5 ? $warning : $normal),
            ];
        }
        return $buttons;
    }

    public static function adminDetails(string $id, string $backCallback): string
    {
        $details = 'manageinvoice_' . $id;
        $withBack = $details . ':' . $backCallback;
        return strlen($withBack) <= 64 ? $withBack : $details;
    }

    public static function show(\PDO $pdo, $chat, $message, string $callback, string $prefix, string $title,
        string $scope, array $params, string|callable $details, array $actions, bool $edit, bool $cached = false, string $summary = ''): void
    {
        $sort = 'recent';
        $direction = 'd';
        $page = 1;
        if (preg_match('/^' . preg_quote($prefix, '/') . ':(recent|username|remaining_bytes|days_remaining):([ad]):([1-9][0-9]{0,6})$/D', $callback, $selection)) {
            [, $sort, $direction, $page] = $selection;
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE $scope");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $limit = 8;
        $pages = max(1, (int) ceil($total / $limit));
        $page = min((int) $page, $pages);
        $rows = ServiceSearch::invoicePage($pdo, $scope, $params, $limit, ($page - 1) * $limit, $sort, $direction === 'a',
            'id_invoice, username, Service_location, Status, time_sell, user_info', !$cached && $total <= 50);
        $current = "$prefix:$sort:$direction:$page";
        $rowTarget = is_string($details) ? $details : static fn(array $row): string => $details($row, $current);
        $buttons = self::buttons($rows, $sort, $direction, $prefix, $rowTarget);
        $caption = "🛍 <b>$title</b>";
        if ($rows === []) {
            $caption .= "\n\nاشتراکی در این فهرست نیست.";
        } else {
            $caption .= "\nبرای مرتب‌سازی، سرستون را بزنید.\n$total اشتراک · صفحه $page از $pages";
            $navigation = [];
            if ($page > 1) { $navigation[] = self::button('قبلی', "$prefix:$sort:$direction:" . ($page - 1)); }
            if ($page < $pages) { $navigation[] = self::button('بعدی', "$prefix:$sort:$direction:" . ($page + 1)); }
            if ($navigation !== []) { $buttons[] = $navigation; }
            if ($cached || $total > 50) { $caption .= "\nحجم و روز از آخرین اطلاعات ثبت‌شده‌اند."; }
        }
        $buttons = array_merge($buttons, $actions);
        $keyboard = json_encode(['inline_keyboard' => $buttons], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($edit) { Editmessagetext($chat, $message, $caption . $summary, $keyboard, 'HTML'); }
        else { sendmessage($chat, $caption . $summary, $keyboard, 'HTML'); }
    }
}
