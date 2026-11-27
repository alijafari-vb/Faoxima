<?php

namespace Faoxima\Telegram\Support;

final class SubscriptionText
{
    public const MY_SUBSCRIPTIONS = '🛍 اشتراک‌های من';

    public static function isLegacyMenuLabel(string $text): bool
    {
        return preg_match('/^(?:🛍️?\h*)?سرویس[\h‌]*های\h+من$/uD', trim($text)) === 1;
    }

    public static function menuLabel(string $text): string
    {
        return self::isLegacyMenuLabel($text) ? self::MY_SUBSCRIPTIONS : $text;
    }

    public static function extendLabel(string $text): string
    {
        return $text === '♻️ تمدید سرویس' ? '♻️ تمدید اشتراک' : $text;
    }

    public static function template(?string $text): string
    {
        $text = $text ?? '';
        $fields = [
            'username|service|service_username|config_username' => [
                'نام اشتراک',
                'نام کاربری(?:[\h‌]+(?:سرویس|اشتراک))?|نام[\h‌]+سرویس|شناسهٔ?[\h‌]+(?:سرویس|اشتراک)',
            ],
            'product|name_product|product_name|name_service' => ['نام پلن', 'نام[\h‌]+(?:سرویس|محصول|اشتراک)'],
            'location|panel_name' => ['موقعیت اشتراک', 'لوکیشن|موقعیت[\h‌]+سرویس'],
        ];
        foreach ($fields as $token => [$label, $oldLabels]) {
            $text = preg_replace('/(?:' . $oldLabels . ')(?=[\h‌]*:[\h‌]*(?:<code>)?\{(?:' . $token . ')\})/u', $label, $text) ?? $text;
        }
        return strtr($text, [
            'سرویس با موفقیت ایجاد شد' => 'اشتراک با موفقیت ایجاد شد',
            'وضعیت سرویس' => 'وضعیت اشتراک',
            'حجم سرویس' => 'حجم اشتراک',
        ]);
    }

    public static function customerLabel(string $text): string
    {
        return strtr($text, [
            'نام کاربری من در لیست نیست' => 'اشتراک من در لیست نیست',
            'نام کاربری  یا لینک اشتراک' => 'نام اشتراک، شناسهٔ اشتراک یا لینک اشتراک',
            'بازگشت به لیست سرویس ها' => 'بازگشت به فهرست اشتراک‌ها',
            'جستجو سریع تر در مدیریت سرویس ها' => 'جستجوی سریع‌تر در مدیریت اشتراک‌ها',
            'روی نام کاربری کلیک کنید' => 'روی نام اشتراک کلیک کنید',
            'پیدا کردن سریع سرویس خود' => 'پیدا کردن سریع اشتراک خود',
            'مشخصات سرویس' => 'مشخصات اشتراک',
            'موقعیت سرویس' => 'موقعیت اشتراک',
            'وضعیت سرویس' => 'وضعیت اشتراک',
            'تمدید سرویس' => 'تمدید اشتراک',
            'اطلاعات سرویس' => 'اطلاعات اشتراک',
            'مشاهده سرویس' => 'مشاهده اشتراک',
            'خرید سرویس' => 'خرید اشتراک',
            'انتقال سرویس' => 'انتقال اشتراک',
            'سرویس‌های خود' => 'اشتراک‌های خود',
            'سرویس مورد نظر' => 'اشتراک مورد نظر',
            'سرویس موردنظر' => 'اشتراک موردنظر',
            'سرویس شما' => 'اشتراک شما',
            'هیچ سرویس فعالی' => 'هیچ اشتراک فعالی',
        ]);
    }

    public static function idLine(?string $id, bool $showUnavailable = false): string
    {
        if ($id === null) {
            return $showUnavailable ? "\n🪪 شناسهٔ اشتراک: در دسترس نیست" : '';
        }
        return "\n🪪 شناسهٔ اشتراک: <code>$id</code>";
    }
}
