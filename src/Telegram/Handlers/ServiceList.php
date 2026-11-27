<?php

namespace Faoxima\Telegram\Handlers;

use Faoxima\Orders\Pricing\ExchangePricing;
use Faoxima\Telegram\Support\ServiceProductIdentity;
use PDO;

/**
 * Handler stage. Inherited by value: $ManagePanel, $_rx_nav_styles, $dataget, $datatextbot, $from_id, $keyboard, $list_marzban_panel_user, $message_id, $pdo, $setting, $telegramRoute, $textbotlang, $update, $user.
 * Inherited or exported by reference: $backuser, $data, $datain, $day, $marzban_list_get, $output, $page, $row, $status, $stmt, $text, $usernameconfig.
 * Returns true to continue the request pipeline, anything else stops it.
 */
final class ServiceList
{
    public static function handle(
        $ManagePanel,
        $_rx_nav_styles,
        &$backuser,
        &$data,
        $dataget,
        &$datain,
        $datatextbot,
        &$day,
        $from_id,
        $keyboard,
        $list_marzban_panel_user,
        &$marzban_list_get,
        $message_id,
        &$output,
        &$page,
        $pdo,
        &$row,
        $setting,
        &$status,
        &$stmt,
        $telegramRoute,
        &$text,
        $textbotlang,
        $update,
        $user,
        &$usernameconfig
    ) {
        switch ($telegramRoute) {
        case 'services':
        case 'services_page':
            return self::browse(
                $pdo, $from_id, $message_id, $datain, $telegramRoute, $dataget[2] ?? null,
                $setting, $datatextbot, $textbotlang, $_rx_nav_styles
            );
        case 'lookup_prompt':
            if (($setting['NotUser'] ?? '') !== 'onnotuser') { return; }
            sendmessage($from_id, '📌 نام اشتراک، شناسهٔ اشتراک یا لینک اشتراک را بفرستید.', $backuser, 'html');
            \Faoxima\Telegram\Navigation\Steps::step('getusernameinfo', $from_id);
            break;
        case 'lookup_name':
            if (($setting['NotUser'] ?? '') !== 'onnotuser' || !isset($update['message'])) { return; }
            if (empty($text))
                return;
            $scope = 'id_user = :owner AND ' . \Faoxima\Services\Subscriptions\ServiceSearch::VISIBLE_STATUS;
            $params = [':owner' => (string) $from_id];
            [$condition, $searchParams] = \Faoxima\Services\Subscriptions\ServiceSearch::condition($pdo, $text, $scope, $params);
            $stmt = $pdo->prepare("SELECT id_invoice, username, Service_location FROM invoice WHERE $scope AND $condition ORDER BY time_sell DESC, id_invoice DESC LIMIT 10");
            $stmt->execute($params + $searchParams);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $buttons = [];
            foreach ($rows as $row) {
                $buttons[] = [['text' => $row['username'], 'callback_data' => 'quickview_' . $row['id_invoice']]];
            }
            if ($buttons === [] && preg_match('~^https?://~i', $text) && strlen($text) <= \Faoxima\Services\Subscriptions\ServiceSearch::MAX_QUERY_LENGTH) {
                $accounts = [];
                $panels = $pdo->prepare("SELECT * FROM marzban_panel WHERE status = 'active' AND agent IN ('all', :agent)");
                $panels->execute([':agent' => (string) ($user['agent'] ?? 'f')]);
                foreach ($panels->fetchAll(\PDO::FETCH_ASSOC) as $panel) {
                    if (in_array((string) $from_id, array_map('strval', (array) json_decode((string) ($panel['hide_user'] ?? ''), true)), true)) { continue; }
                    $name = \Faoxima\Services\Subscriptions\ServiceSearch::externalPanelUsername($panel, $text);
                    if ($name !== null && self::allowedExternal($pdo, $panel, $name, (string) $from_id)) {
                        $accounts[$panel['code_panel']] = $name;
                        $buttons[] = [['text' => $panel['name_panel'], 'callback_data' => 'locationnotuser_' . $panel['code_panel']]];
                    }
                }
                if ($accounts !== []) {
                    \Faoxima\Core\Database\Db::update('user', 'Processing_value', json_encode($accounts), 'id', $from_id);
                    \Faoxima\Telegram\Navigation\Steps::step('getdata', $from_id);
                    sendmessage($from_id, 'پنل اشتراک را انتخاب کنید.', json_encode(['inline_keyboard' => $buttons]), 'HTML');
                    return;
                }
            }
            $buttons[] = [['text' => $textbotlang['users']['stateus']['backlist'], 'callback_data' => 'backorder']];
            sendmessage($from_id, $rows === [] ? 'اشتراکی پیدا نشد. برای اشتراک ثبت‌نشده در فاکسیما، لینک کامل را بفرستید.' : 'اشتراک را انتخاب کنید.', json_encode(['inline_keyboard' => $buttons]), 'HTML');
            \Faoxima\Telegram\Navigation\Steps::step($rows === [] ? 'getusernameinfo' : 'home', $from_id);
            break;
        case 'lookup_panel':
            if (($user['step'] ?? null) !== 'getdata' || ($setting['NotUser'] ?? '') !== 'onnotuser') { return; }
            $marzban_list_get = \Faoxima\Core\Database\Db::select('marzban_panel', '*', 'code_panel', $dataget[1], 'select');
            $accounts = json_decode((string) ($user['Processing_value'] ?? ''), true);
            $lookupName = $accounts[$dataget[1]] ?? null;
            if (!is_string($lookupName) || !is_array($marzban_list_get)
                || ($marzban_list_get['status'] ?? '') !== 'active'
                || !in_array($marzban_list_get['agent'] ?? '', ['all', $user['agent']], true)
                || in_array((string) $from_id, array_map('strval', (array) json_decode((string) ($marzban_list_get['hide_user'] ?? ''), true)), true)
                || !self::allowedExternal($pdo, $marzban_list_get, $lookupName, (string) $from_id)) {
                sendmessage($from_id, 'اشتراکی پیدا نشد.', $backuser, 'HTML');
                return;
            }
            $DataUserOut = $ManagePanel->DataUser($marzban_list_get['name_panel'], $lookupName);
            if (($DataUserOut['status'] ?? 'Unsuccessful') === 'Unsuccessful') {
                sendmessage($from_id, $textbotlang['users']['stateus']['notUsernameget'], $backuser, 'HTML');
                return;
            }
            \Faoxima\Core\Database\Db::update('user', 'Processing_value', $lookupName, 'id', $from_id);
            \Faoxima\Core\Database\Db::update('user', 'Processing_value_four', $dataget[1], 'id', $from_id);

            $status = $DataUserOut['status'];
            $bExp = is_numeric($DataUserOut['expire'] ?? null) ? (int)$DataUserOut['expire'] : 0;
            $bDl = is_numeric($DataUserOut['data_limit'] ?? null) ? (float)$DataUserOut['data_limit'] : 0.0;
            $bUt = is_numeric($DataUserOut['used_traffic'] ?? null) ? (float)$DataUserOut['used_traffic'] : 0.0;
            if ($bExp > 0 && $bExp <= time()) {
                $status = 'expired';
            } elseif ($bDl > 0.0 && $bUt >= $bDl) {
                $status = 'limited';
            }
            $status_map = [
                'active' => $textbotlang['users']['stateus']['active'],
                'limited' => $textbotlang['users']['stateus']['limited'],
                'end_of_volume' => $textbotlang['users']['stateus']['limited'],
                'disabled' => $textbotlang['users']['stateus']['disabled'],
                'deactivev' => $textbotlang['users']['stateus']['disabled'],
                'expired' => $textbotlang['users']['stateus']['expired'],
                'end_of_time' => $textbotlang['users']['stateus']['expired'],
                'on_hold' => $textbotlang['users']['stateus']['on_hold'],
                'send_on_hold' => $textbotlang['users']['stateus']['on_hold'],
                'Unknown' => $textbotlang['users']['stateus']['Unknown']
            ];
            $status_var = $status_map[$status] ?? ($textbotlang['users']['stateus']['active'] ?? 'فعال');

            $expirationDate = $DataUserOut['expire']
                ? jdate('Y/m/d', $DataUserOut['expire'])
                : (($DataUserOut['status'] ?? '') === 'on_hold' ? $textbotlang['users']['stateus']['PendingFirstConnection'] : $textbotlang['users']['stateus']['Unlimited']);

            $LastTraffic = $DataUserOut['data_limit'] ? \Faoxima\Core\Support\Formatting::formatBytes($DataUserOut['data_limit']) : $textbotlang['users']['stateus']['Unlimited'];

            $output = $DataUserOut['data_limit'] - $DataUserOut['used_traffic'];
            $RemainingVolume = $DataUserOut['data_limit'] ? \Faoxima\Core\Support\Formatting::formatBytes($output) : "نامحدود";

            $usedTrafficGb = $DataUserOut['used_traffic'] ? \Faoxima\Core\Support\Formatting::formatBytes($DataUserOut['used_traffic']) : $textbotlang['users']['stateus']['Notconsumed'];

            $timeDiff = $DataUserOut['expire'] - time();
            $day = $DataUserOut['expire'] ? floor($timeDiff / 86400) . $textbotlang['users']['stateus']['day'] : $textbotlang['users']['stateus']['Unlimited'];

            $keyboardinfo = [
                'inline_keyboard' => [
                    [
                        ['text' => $DataUserOut['username'], 'callback_data' => "username", '_rxcolor' => 'subscription_field:username:value'],
                        ['text' => $textbotlang['users']['stateus']['username'], 'callback_data' => 'username', '_rxcolor' => 'subscription_field:username:label'],
                    ],
                    [
                        ['text' => $status_var, 'callback_data' => 'status_var', '_rxsk' => 'Status', '_rxcolor' => 'subscription_field:Status:value'],
                        ['text' => $textbotlang['users']['stateus']['stateus'], 'callback_data' => 'status_var', '_rxsk' => 'Status', '_rxcolor' => 'subscription_field:Status:label'],
                    ],
                    [
                        ['text' => $expirationDate, 'callback_data' => 'expirationDate', '_rxcolor' => 'subscription_field:expirationDate:value'],
                        ['text' => $textbotlang['users']['stateus']['expirationDate'], 'callback_data' => 'expirationDate', '_rxcolor' => 'subscription_field:expirationDate:label'],
                    ],
                    [],
                    [
                        ['text' => $day, 'callback_data' => 'روز', '_rxsk' => 'daysleft', '_rxcolor' => 'subscription_field:daysleft:value'],
                        ['text' => $textbotlang['users']['stateus']['daysleft'], 'callback_data' => 'day', '_rxsk' => 'daysleft', '_rxcolor' => 'subscription_field:daysleft:label'],
                    ],
                    [
                        ['text' => $LastTraffic, 'callback_data' => 'LastTraffic', '_rxcolor' => 'subscription_field:LastTraffic:value'],
                        ['text' => $textbotlang['users']['stateus']['LastTraffic'], 'callback_data' => 'LastTraffic', '_rxcolor' => 'subscription_field:LastTraffic:label'],
                    ],
                    [
                        ['text' => $usedTrafficGb, 'callback_data' => 'expirationDate', '_rxsk' => 'usedtraffic', '_rxcolor' => 'subscription_field:usedtraffic:value'],
                        ['text' => $textbotlang['users']['stateus']['usedTrafficGb'], 'callback_data' => 'expirationDate', '_rxsk' => 'usedtraffic', '_rxcolor' => 'subscription_field:usedtraffic:label'],
                    ],
                    [
                        ['text' => $RemainingVolume, 'callback_data' => 'RemainingVolume', '_rxcolor' => 'subscription_field:RemainingVolume:value'],
                        ['text' => $textbotlang['users']['stateus']['RemainingVolume'], 'callback_data' => 'RemainingVolume', '_rxcolor' => 'subscription_field:RemainingVolume:label'],
                    ]
                ]
            ];
            $marzbanstatusextra = \Faoxima\Services\Panels\PanelFeatures::panel_feature_enabled($marzban_list_get, 'extravolume') ? "onextra" : "offextra";
            if ($marzbanstatusextra == "onextra") {
                $keyboardinfo['inline_keyboard'][] = [
                    ['text' => $textbotlang['users']['extend']['title'], 'callback_data' => 'extends_' . $DataUserOut['username'] . "_" . $dataget[1]],
                    ['text' => $textbotlang['users']['Extra_volume']['sellextra'], 'callback_data' => 'Extra_volumes_' . $DataUserOut['username'] . '_' . $dataget[1]],
                ];
            } else {
                $keyboardinfo['inline_keyboard'][] = [['text' => $textbotlang['users']['extend']['title'], 'callback_data' => 'extends_' . $DataUserOut['username'] . "_" . $dataget[1]]];
            }
            $keyboardinfo = json_encode($keyboardinfo);
            Editmessagetext($from_id, $message_id, $textbotlang['users']['stateus']['info'], $keyboardinfo);
            sendmessage($from_id, $textbotlang['users']['selectoption'], $keyboard, 'html');
            \Faoxima\Telegram\Navigation\Steps::step('home', $from_id);
            break;
        }

        return true;
    }

    private static function allowedExternal($pdo, array $panel, string $username, string $owner): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM invoice WHERE Service_location = :panel AND BINARY username = :name AND id_user <> :owner LIMIT 1');
        $stmt->execute([':panel' => $panel['name_panel'], ':name' => $username, ':owner' => $owner]);
        return $stmt->fetchColumn() === false;
    }

    private static function browse($pdo, $fromId, $messageId, &$callback, $route, $legacyProductKey, $setting, $texts, $language, $navigationStyles)
    {
        if ($route === 'services') {
            \Faoxima\Telegram\Media\SectionMedia::rx_section_media_enter('services', $fromId, $callback, $messageId, ['myservicesbtn'], ['myservicesbtn']);
        }
        $actions = [];
        if (($setting['NotUser'] ?? '') === 'onnotuser') {
            $actions[] = [\Faoxima\Services\Subscriptions\SubscriptionTable::button('🔎 اشتراک من در لیست نیست', 'notusernameme')];
        }
        $actions[] = [
            \Faoxima\Services\Subscriptions\SubscriptionTable::button($language['users']['search']['title'], 'searchservice'),
            \Faoxima\Services\Subscriptions\SubscriptionTable::button('بازگشت', 'backuser', 'danger'),
        ];
        \Faoxima\Services\Subscriptions\SubscriptionTable::show($pdo, $fromId, $messageId, (string) $callback, 'myst', 'اشتراک‌های من',
            'id_user = :owner AND ' . \Faoxima\Services\Subscriptions\ServiceSearch::VISIBLE_STATUS, [':owner' => (string) $fromId], 'quickview_', $actions, $callback !== '');
    }
}
