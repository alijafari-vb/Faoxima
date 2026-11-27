<?php

namespace Faoxima\Services\Subscriptions;

final class PlanLabel
{
    public static function terms(array $row): array
    {
        $volume = $row['Volume_constraint'] ?? $row['Volume'] ?? null;
        $duration = $row['Service_time'] ?? null;
        $unit = \Faoxima\Core\Support\Formatting::rxInvoiceVolumeUnit($row) === 'MB' ? 'مگ' : 'گیگ';
        $size = is_numeric($volume) ? rtrim(rtrim(number_format((float) $volume, 2, '.', ''), '0'), '.') : '';
        $timeUnit = ($row['name_product'] ?? '') === 'سرویس تست' ? 'ساعت' : 'روز';
        return [
            !is_numeric($volume) || $volume < 0 ? '—' : ($volume > 0 ? "$size $unit" : 'نامحدود'),
            !is_numeric($duration) || $duration < 0 ? '—' : ($duration > 0 ? (int) $duration . " $timeUnit" : 'نامحدود'),
        ];
    }

    public static function offer(array $row): string
    {
        [$traffic, $time] = self::terms($row);
        return ($traffic === 'نامحدود' ? 'حجم نامحدود' : $traffic) . ' · '
            . ($time === 'نامحدود' ? 'زمان نامحدود' : $time);
    }

    public static function display(array $row): ?string
    {
        [$traffic, $time] = self::terms($row);
        if ($traffic === '—' || $time === '—') {
            return null;
        }

        $name = (string) ($row['name_product'] ?? '');
        $isTest = $name === 'سرویس تست';
        $code = $row['code_product'] ?? '';
        if ($code === '' && !empty($row['pricing_snapshot'])) {
            $snapshot = json_decode((string) $row['pricing_snapshot'], true);
            $code = $snapshot['product'] ?? '';
        }
        $isCustom = in_array($code, ['customvolume', 'custom_volume'], true)
            || in_array($name, ['⚙️ سرویس دلخواه', '🛍 حجم دلخواه', 'سرویس سفارشی'], true);
        $prefix = $isTest ? 'تست' : ($isCustom ? 'دلخواه' : 'پیش‌فرض');
        return $prefix . ' ' . ($traffic === $time ? $traffic : "$traffic $time");
    }
}
