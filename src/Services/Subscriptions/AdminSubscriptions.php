<?php

namespace Faoxima\Services\Subscriptions;

use Faoxima\Telegram\Navigation\Steps;
use PDO;

final class AdminSubscriptions
{
    public static function handle(PDO $pdo, $chat, $message, string $callback): bool
    {
        $back = [SubscriptionTable::button('بازگشت', 'subadmin', 'danger')];
        if ($callback === 'subadmin') {
            Steps::step('home', $chat);
            Editmessagetext($chat, $message, '🛍 <b>مدیریت اشتراک‌ها</b>', json_encode(['inline_keyboard' => [
                [SubscriptionTable::button('همهٔ اشتراک‌ها', 'sadm:all:recent:d:1')],
                [SubscriptionTable::button('اشتراک‌های آنلاین', 'sadm:online:recent:d:1')],
                [SubscriptionTable::button('جستجوی اشتراک', 'subadmin_search')],
                [SubscriptionTable::button('بازگشت به مدیریت', 'adm_hub_main', 'danger')],
            ]]), 'HTML');
            return true;
        }
        if ($callback === 'subadmin_search') {
            Steps::step('subscription_search', $chat);
            Editmessagetext($chat, $message, 'نام اشتراک، شناسهٔ اشتراک یا لینک اشتراک را بفرستید.', json_encode(['inline_keyboard' => [$back]]), 'HTML');
            return true;
        }
        if (!preg_match('/^sadm:(all|online):(recent|username|remaining_bytes|days_remaining):[ad]:[1-9][0-9]{0,6}$/D', $callback, $match)) {
            return false;
        }
        $online = $match[1] === 'online';
        [$scope, $params, $unavailable] = $online ? ServiceSearch::onlineScope($pdo) : [ServiceSearch::VISIBLE_STATUS, [], 0];
        $summary = $unavailable > 0 ? "\nوضعیت اتصال $unavailable پنل در دسترس نیست." : '';
        $back[] = SubscriptionTable::button('جستجوی اشتراک', 'subadmin_search');
        Steps::step('home', $chat);
        SubscriptionTable::show($pdo, $chat, $message, $callback, 'sadm:' . $match[1],
            $online ? 'اشتراک‌های آنلاین' : 'همهٔ اشتراک‌ها', $scope, $params,
            static fn(array $row, string $returnTo): string => SubscriptionTable::adminDetails($row['id_invoice'], $returnTo),
            [$back], true, true, $summary);
        return true;
    }
}
