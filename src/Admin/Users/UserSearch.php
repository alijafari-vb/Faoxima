<?php

namespace Faoxima\Admin\Users;

use PDO;

final class UserSearch
{
    public static function telegramId(PDO $pdo, string $input, array $message = []): ?string
    {
        if (isset($message['forward_origin'])) {
            $origin = $message['forward_origin'];
            return ($origin['type'] ?? '') === 'user' ? \Faoxima\Services\Subscriptions\ServiceSearch::numericId($origin['sender_user']['id'] ?? null) : null;
        }
        if (isset($message['forward_from'])) {
            return \Faoxima\Services\Subscriptions\ServiceSearch::numericId($message['forward_from']['id'] ?? null);
        }
        if (isset($message['forward_sender_name']) || isset($message['forward_from_chat'])) {
            return null;
        }

        $input = trim($input);
        $id = \Faoxima\Services\Subscriptions\ServiceSearch::numericId($input);
        if ($id !== null) {
            return $id;
        }
        $username = \Faoxima\Services\Subscriptions\ServiceSearch::telegramUsername($input);
        if ($username === null || \Faoxima\Services\Subscriptions\ServiceSearch::isNumericQuery($input)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM user WHERE BINARY LOWER(username) = BINARY LOWER(:username) LIMIT 2');
        $stmt->execute([':username' => $username]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (string) $ids[0] : null;
    }

    public static function condition(PDO $pdo, string $query, string $scope = '1=1'): array
    {
        $query = trim($query);
        $phone = preg_replace('/[\h()-]+/u', '', \Faoxima\Services\Subscriptions\ServiceSearch::asciiDigits($query));
        if (preg_match('/^(?:09[0-9]{9}|\+[0-9]{8,15})$/D', $phone)) {
            $local = preg_replace('/^\+98(?=9[0-9]{9}$)/', '0', $phone);
            $international = preg_replace('/^0(?=9[0-9]{9}$)/', '+98', $local);
            $params = [':local_phone' => $local, ':intl_phone' => $international, ':local_alt' => $local, ':intl_alt' => $international];
            return ['(number IN (:local_phone, :intl_phone) OR number_username IN (:local_alt, :intl_alt))', $params];
        }
        if (\Faoxima\Services\Subscriptions\ServiceSearch::isNumericQuery($query)) {
            $id = \Faoxima\Services\Subscriptions\ServiceSearch::numericId($query);
            return $id === null ? ['0 = 1', []] : ['id = :telegram_id', [':telegram_id' => $id]];
        }

        $handle = \Faoxima\Services\Subscriptions\ServiceSearch::telegramUsername($query);
        $explicit = str_starts_with($query, '@') || preg_match('~^https?://~i', $query);
        if ($handle !== null) {
            $where = 'BINARY LOWER(username) = BINARY LOWER(:telegram_name)';
            $params = [':telegram_name' => $handle];
            if ($explicit) {
                return [$where, $params];
            }
            $stmt = $pdo->prepare("SELECT 1 FROM user WHERE ($scope) AND $where LIMIT 1");
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                return [$where, $params];
            }
        }
        if ($explicit) {
            return ['0 = 1', []];
        }
        $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        return ["(username LIKE :user_like ESCAPE '!' OR namecustom LIKE :user_custom ESCAPE '!')", [':user_like' => $like, ':user_custom' => $like]];
    }
}
