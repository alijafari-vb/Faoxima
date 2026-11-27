<?php

namespace Faoxima\Telegram\Handlers;

/**
 * Handler stage. Inherited by value: $_rx_nav_styles, $connect, $from_id, $message_id, $pdo, $previous_page, $statusnote, $telegramRoute, $textbotlang, $user.
 * Inherited or exported by reference: $backuser, $data, $datain, $next_page, $page, $pages, $result, $row, $stmt.
 * Returns true to continue the request pipeline, anything else stops it.
 */
final class RenewalList
{
    public static function handle(
        $_rx_nav_styles,
        &$backuser,
        $connect,
        &$data,
        &$datain,
        $from_id,
        $message_id,
        &$next_page,
        &$page,
        &$pages,
        $pdo,
        $previous_page,
        &$result,
        &$row,
        $statusnote,
        &$stmt,
        $telegramRoute,
        $textbotlang,
        $user
    ) {
        if (!in_array($telegramRoute, ['renewal_list', 'renewal_next', 'renewal_previous'], true)) {
            return true;
        }

        if ($telegramRoute === 'renewal_list') {
            \Faoxima\Telegram\Media\SectionMedia::rx_section_media_enter('extend', $from_id, $datain, $message_id, ['extendbtn']);
        }
        $scope = "id_user = :owner AND Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')";
        $actions = [[\Faoxima\Services\Subscriptions\SubscriptionTable::button('بازگشت', 'backuser', 'danger')]];
        \Faoxima\Services\Subscriptions\SubscriptionTable::show($pdo, $from_id, $message_id, (string) $datain, 'renewt', 'تمدید اشتراک',
            $scope, [':owner' => (string) $from_id], 'extend_', $actions, $datain !== '');
    }
}
