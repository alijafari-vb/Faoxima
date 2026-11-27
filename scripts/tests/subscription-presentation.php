<?php

use Faoxima\Api\Handlers\KeyboardLayoutHandler;
use Faoxima\Services\Delivery\ServiceDelivery;
use Faoxima\Services\Subscriptions\PlanLabel;
use Faoxima\Services\Subscriptions\ServiceSearch;
use Faoxima\Telegram\Bootstrap\BotLocalization;
use Faoxima\Telegram\Routing\AccountRoutes;
use Faoxima\Telegram\Support\SubscriptionText;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/botapi.php';

function same($expected, $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($case . ': ' . var_export($actual, true));
    }
}

$rows = [
    ['id_text' => 'text_Purchased_services', 'text' => '🛍 سرویس های من'],
    ['id_text' => 'text_extend', 'text' => '♻️ تمدید سرویس'],
];
$catalog = BotLocalization::textCatalog($rows);
same('🛍 اشتراک‌های من', $catalog['text_Purchased_services'], 'existing bot menu text');
same('🛍 اشتراک‌های من', KeyboardLayoutHandler::describe($rows, null)['text']['text_Purchased_services'], 'existing Mini App menu text');
same('♻️ تمدید اشتراک', $catalog['text_extend'], 'existing renewal menu text');
same('اشتراک‌های دفتر', SubscriptionText::menuLabel('اشتراک‌های دفتر'), 'custom menu text is preserved');
$match = [];
foreach ([$rows[0]['text'], $catalog['text_Purchased_services']] as $incoming) {
    same('services', AccountRoutes::account(['step' => 'home'], $incoming, '', ['users' => ['backbtn' => 'بازگشت']], $catalog, 1, [], $match),
        'an already-sent menu button still routes to subscriptions');
}

$old = "✅ سرویس با موفقیت ایجاد شد\nنام کاربری سرویس : {username}\nنام سرویس: {name_service}\nلوکیشن: {location}";
$new = "✅ اشتراک با موفقیت ایجاد شد\nنام اشتراک : {username}\nنام پلن: {name_service}\nموقعیت اشتراک: {location}";
same($new, SubscriptionText::template($old), 'old delivered purchase text');
same("نام پلن: {product}\nنام اشتراک: <code>{username}</code>", SubscriptionText::template("نام اشتراک: {product}\nشناسه سرویس: <code>{username}</code>"),
    'a username is not labelled as its numeric ID');
same(['20 گیگ', '30 روز'], PlanLabel::terms(['Volume_constraint' => '20.00', 'Service_time' => '30']), 'plan table uses numeric terms');
same(['نامحدود', 'نامحدود'], PlanLabel::terms(['Volume_constraint' => 0, 'Service_time' => '0']), 'zero denotes unlimited in a plan');

$cached = ['username' => 'demo', 'user_info' => '{"username":"demo","panel_user_id":123456}'];
same("\n🪪 شناسهٔ اشتراک: <code>123456</code>", SubscriptionText::idLine(ServiceSearch::cachedPanelUserId($cached)), 'detail shows full cached panel ID');
same(null, ServiceSearch::cachedPanelUserId(array_replace($cached, ['username' => 'another'])), 'cached ID from a different username is ignored');

$panel = ['type' => 'rebecca', 'sublink' => 'onsublink'];
$original = 'https://sub.example.test:443/sub/test?x=1&y=2';
$link = 'https://sub.example.test/sub/test?x=1&y=2';
same($link, ServiceDelivery::rxResolveConnectionLink($panel, $original), 'default HTTPS port removed without altering path or query');
foreach (['http://sub.example.test:443/sub/test', 'https://sub.example.test:8443/sub/test', 'vless://demo@sub.example.test:443'] as $other) {
    same($other, ServiceDelivery::rxNormalizeSubscriptionUrl($other), 'a connection or nondefault port remains intact');
}
$GLOBALS['setting'] = ['subscription_link_button' => 'off'];
$caption = ServiceDelivery::rxAppendSubscriptionLinkLine('اشتراک آماده است', $link, $panel);
same("اشتراک آماده است\n\n🔗 لینک اشتراک:\n<code>https://sub.example.test/sub/test?x=1&amp;y=2</code>", $caption, 'separate right-to-left caption and copyable code');
$kb = ServiceDelivery::rxBuildDeliveryOffer($panel, 'order123', false, true, null, $link);
$button = json_decode(processKeyboardStyles($kb), true)['inline_keyboard'][0][0];
same(['text' => $link], $button['copy_text'], 'inline Telegram copy receives the exact unescaped URL');
same(null, ServiceDelivery::rxBuildDeliveryOffer($panel, 'order123', false, true, null, str_repeat('a', 257)), 'long URLs are copied through the code field');
$GLOBALS['setting']['subscription_link_button'] = 'on';
same('subscriptionurl_order123', json_decode(ServiceDelivery::rxBuildDeliveryOffer($panel, 'order123', false, true, null, $link), true)['inline_keyboard'][0][0]['callback_data'],
    'keep the original retrieve-link button when enabled');

echo "Subscription menu, templates, numeric IDs, plan terms and link delivery passed.\n";
