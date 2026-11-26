<?php

require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/../../lib/ServiceSearch.php';

function fx_user_search_condition(PDO $pdo, string $query, string $scope = '1=1'): array
{
    $query = trim($query);
    $phone = preg_replace('/[\h()-]+/u', '', ServiceSearch::asciiDigits($query));
    if (preg_match('/^(?:09[0-9]{9}|\+[0-9]{8,15})$/D', $phone)) {
        $local = preg_replace('/^\+98(?=9[0-9]{9}$)/', '0', $phone);
        $international = preg_replace('/^0(?=9[0-9]{9}$)/', '+98', $local);
        $params = [':local_phone' => $local, ':intl_phone' => $international, ':local_alt' => $local, ':intl_alt' => $international];
        return ['(number IN (:local_phone, :intl_phone) OR number_username IN (:local_alt, :intl_alt))', $params];
    }
    if (ServiceSearch::isNumericQuery($query)) {
        $id = ServiceSearch::numericId($query);
        return $id === null ? ['0 = 1', []] : ['id = :telegram_id', [':telegram_id' => $id]];
    }

    $handle = ServiceSearch::telegramUsername($query);
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

if (!function_exists('fx_search_current')) {

    function fx_search_current(string $param = 'q'): string
    {
        return trim((string)($_GET[$param] ?? ''));
    }
}

if (!function_exists('fx_search_ui')) {

    function fx_search_ui(string $baseUrl, string $current, array $keepParams = [], string $placeholder = 'جستجو…', string $param = 'q', bool $privateSearch = false): string
    {
        $html = '<div class="card fx-search-filter">';
        $html .= '<form method="' . ($privateSearch ? 'POST' : 'GET') . '" action="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '" class="fx-search-filter__form">';
        $hiddenLookup = $privateSearch && !empty($keepParams['lookup']);
        if ($privateSearch) {
            unset($keepParams['lookup']);
            $html .= fx_csrf_field() . '<input type="hidden" name="action" value="service_lookup">';
        }
        foreach ($keepParams as $k => $v) {
            if ($v === null || $v === '') continue;
            $html .= '<input type="hidden" name="' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '<input type="search" autocomplete="off" spellcheck="false" name="' . htmlspecialchars($param, ENT_QUOTES, 'UTF-8') . '" class="fx-search-filter__input" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($hiddenLookup ? '' : $current, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<button type="submit" class="btn btn-primary fx-search-filter__btn">جستجو</button>';
        if ($current !== '' || $hiddenLookup) {
            $qs = fx_qs($keepParams, [$param => null]);
            $html .= '<a class="btn btn-outline fx-search-filter__btn" href="' . htmlspecialchars($baseUrl . '?' . $qs, ENT_QUOTES, 'UTF-8') . '">حذف فیلتر</a>';
        }
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
}
