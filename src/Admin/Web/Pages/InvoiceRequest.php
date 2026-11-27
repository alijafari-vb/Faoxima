<?php

namespace Faoxima\Admin\Web\Pages;

use PDO;

/**
 * Handler stage. Inherited by value: $pdo.
 * Inherited or exported by reference: $df, $invDateKeep, $invDeleteBlocked, $invDeleteNote, $invExtraKeep, $invFilterActive, $invFilterCriteria, $invFilterParams, $invQ, $invStatus, $invStatusActive, $invStatusOptions, $listinvoice, $pg, $query, $result, $v.
 * Returns true to continue the request pipeline, anything else stops it.
 */
final class InvoiceRequest
{
    public static function handle(
        &$df,
        &$invDateKeep,
        &$invDeleteBlocked,
        &$invDeleteNote,
        &$invExtraKeep,
        &$invFilterActive,
        &$invFilterCriteria,
        &$invFilterParams,
        &$invQ,
        &$invStatus,
        &$invStatusActive,
        &$invStatusOptions,
        &$listinvoice,
        $pdo,
        &$pg,
        &$query,
        &$result,
        &$v
    ) {
        $query = $pdo->prepare("SELECT * FROM admin WHERE username=:username");
        $query->bindParam("username", $_SESSION["user"], PDO::PARAM_STR);
        $query->execute();
        $result = $query->fetch(PDO::FETCH_ASSOC);

        if (!isset($_SESSION["user"]) || !$result) {
            header('Location: login.php');
            return;
        }

        \Faoxima\Admin\Web\Support\Csrf::fx_csrf_guard();

        $principal = ($result['rule'] ?? '') === 'administrator';
        if ($principal && ($_POST['action'] ?? '') === 'service_lookup') {
            $term = is_string($_POST['q'] ?? null) ? trim($_POST['q']) : '';
            $keep = array_intersect_key($_POST, array_flip(['status', 'dpreset', 'ddays', 'dfrom', 'dto', 'min_price', 'max_price', 'product', 'pmatch', 'location']));
            if ($term !== '' && strlen($term) <= \Faoxima\Services\Subscriptions\ServiceSearch::MAX_QUERY_LENGTH) {
                if (\Faoxima\Services\Subscriptions\ServiceSearch::isPrivate($term)) {
                    $key = bin2hex(random_bytes(16));
                    $lookups = array_filter($_SESSION['invoice_service_lookups'] ?? [], static fn($entry) => $entry['until'] > time());
                    $lookups[$key] = ['term' => $term, 'until' => time() + 900];
                    $_SESSION['invoice_service_lookups'] = array_slice($lookups, -8, null, true);
                    $keep['lookup'] = $key;
                } else {
                    $keep['q'] = $term;
                }
            }
            header('Location: invoice.php?' . http_build_query($keep), true, 303);
            exit;
        }
        $invQ = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $lookup = $principal && is_string($_GET['lookup'] ?? null) ? $_GET['lookup'] : '';
        $stored = $_SESSION['invoice_service_lookups'][$lookup] ?? [];
        $search = $lookup !== '' ? (($stored['until'] ?? 0) > time() ? $stored['term'] : '') : $invQ;
        $sensitive = $lookup !== '' || \Faoxima\Services\Subscriptions\ServiceSearch::isPrivate($search);
        if ($sensitive) { $invQ = ''; }
        $df = \Faoxima\Admin\Web\Support\DateFilter::fx_date_filter_resolve();
        $invStatusOptions = [
            'unpaid'        => 'در انتظار پرداخت',
            'active'        => 'فعال',
            'disabledn'     => 'ناموجود در پنل',
            'disabled'      => 'غیرفعال شده',
            'end_of_time'   => 'اتمام زمان',
            'end_of_volume' => 'اتمام حجم',
            'sendedwarn'    => 'هشدار پایانی',
            'send_on_hold'  => 'خطای اتصال',
            'removebyuser'  => 'حذف توسط کاربر',
            'removebyadmin' => 'حذف توسط ادمین',
        ];
        $invStatus = \Faoxima\Admin\Web\Support\StatusFilter::fx_status_filter_current();
        $invAf = \Faoxima\Admin\Web\Support\ExtraFilter::fx_amount_filter_resolve();
        $invProduct = \Faoxima\Admin\Web\Support\ExtraFilter::fx_extra_text_param('product');
        $invLocation = \Faoxima\Admin\Web\Support\ExtraFilter::fx_extra_text_param('location', 300);
        $invPmatchRaw = $_GET['pmatch'] ?? '';
        $invPmatch = $invPmatchRaw === 'exact' ? 'exact' : '';
        $invExtraKeep = array_merge(\Faoxima\Admin\Web\Support\ExtraFilter::fx_amount_filter_keep($invAf), [
            'product' => $invProduct !== '' ? $invProduct : null,
            'pmatch' => $invPmatch !== '' ? $invPmatch : null,
            'location' => $invLocation !== '' ? $invLocation : null,
        ]);
        $invTestScope = $invPmatch === 'exact' && $invProduct === 'سرویس تست';

        $invDeleteBlocked = !is_string($invPmatchRaw) || ($invPmatchRaw !== '' && $invPmatch === '');
        foreach (['min_price', 'max_price'] as $invAk) {
            $invAv = $_GET[$invAk] ?? '';
            if (!is_string($invAv) || (trim($invAv) !== '' && \Faoxima\Admin\Web\Support\ExtraFilter::fx_amount_param($invAk) === null)) $invDeleteBlocked = true;
        }
        foreach (['product' => 200, 'location' => 300] as $invTk => $invTmax) {
            $invTv = $_GET[$invTk] ?? '';
            if (!is_string($invTv) || mb_strlen(trim($invTv), 'UTF-8') > $invTmax) $invDeleteBlocked = true;
        }
        foreach (['dpreset', 'ddays', 'dfrom', 'dto'] as $invDk) {
            $invDv = $_GET[$invDk] ?? '';
            if ((!is_string($invDv) || trim($invDv) !== '') && !$df['active']) $invDeleteBlocked = true;
        }

        $whereSql = '1=1';
        $whereParams = [];
        if ($df['active']) {
            $whereSql .= ' AND time_sell BETWEEN :dfrom AND :dto';
            $whereParams[':dfrom'] = $df['from'];
            $whereParams[':dto'] = $df['to'];
        }
        $whereSql .= \Faoxima\Admin\Web\Support\StatusFilter::fx_status_filter_sql('Status', $invStatus, $invStatusOptions, $whereParams, ':statusVal');
        $whereSql .= \Faoxima\Admin\Web\Support\ExtraFilter::fx_amount_filter_sql('price_product', $invAf, $whereParams, 'am');
        if ($invProduct !== '' && $invPmatch === 'exact') {
            $whereSql .= ' AND name_product = :xprod';
            $whereParams[':xprod'] = $invProduct;
        } elseif ($invProduct !== '') {
            $whereSql .= ' AND name_product LIKE :xprod';
            $whereParams[':xprod'] = '%' . $invProduct . '%';
        }
        if ($invLocation !== '') {
            $whereSql .= ' AND Service_location LIKE :xloc';
            $whereParams[':xloc'] = '%' . $invLocation . '%';
        }

        if ($search !== '' || $lookup !== '') {
            if ($sensitive && $lookup === '') {
                $condition = '0=1';
                $params = [];
            } elseif (!$principal && \Faoxima\Services\Subscriptions\ServiceSearch::isNumericQuery($search)) {
                $condition = 'id_user = :telegram_id';
                $params = [':telegram_id' => \Faoxima\Services\Subscriptions\ServiceSearch::numericId($search)];
            } else {
                [$condition, $params] = \Faoxima\Services\Subscriptions\ServiceSearch::condition($pdo, $search, $whereSql, $whereParams, ['id_invoice', 'id_user', 'username', 'name_product']);
            }
            $whereSql .= ' AND ' . $condition;
            $whereParams += $params;
        }
        $invDeleteBlocked = $invDeleteBlocked || $sensitive || ($principal && \Faoxima\Services\Subscriptions\ServiceSearch::isNumericQuery($search));
        if ($lookup !== '') { $invExtraKeep['lookup'] = $lookup; }

        $invStatusActive = $invStatus !== '' && isset($invStatusOptions[$invStatus]);
        if ($invStatus !== '' && !$invStatusActive) $invDeleteBlocked = true;
        $invFilterActive = $invQ !== '' || $df['active'] || $invStatusActive || $invAf['active'] || $invProduct !== '' || $invLocation !== '';
        $invDateKeep = \Faoxima\Admin\Web\Support\BulkDelete::fx_filter_delete_date_params('', $df['active']);
        $invFilterParams = array_merge(['q' => $invQ !== '' ? $invQ : null, 'status' => $invStatusActive ? $invStatus : null], $invDateKeep, $invExtraKeep);
        $invExtraCriteria = array_filter([
            'مبلغ' => $invAf['label'],
            'محصول' => $invProduct !== '' ? ($invPmatch === 'exact' ? $invProduct . ' (تطابق دقیق)' : $invProduct) : '',
            'لوکیشن' => $invLocation,
        ], function ($v) { return $v !== ''; });
        $invFilterCriteria = array_merge(\Faoxima\Admin\Web\Support\BulkDelete::fx_filter_delete_criteria($invStatusActive ? $invStatusOptions[$invStatus] : '', $df, $lookup !== '' ? 'مرجع اشتراک' : $invQ), $invExtraCriteria);
        $invDeleteNote = ($invTestScope ? 'حذف فقط به رکوردهای فاکتور «سرویس تست» مطابق فیلتر محدود است. ' : '')
            . \Faoxima\Services\Subscriptions\InvoiceDeletion::RESTRICTION;
        if (\Faoxima\Admin\Web\Support\BulkDelete::fx_filter_delete_requested()) {
            $fdRes = ($invFilterActive && !$invDeleteBlocked) ? \Faoxima\Admin\Web\Support\BulkDelete::fx_filter_delete_where($pdo, 'invoice', "($whereSql) AND " . \Faoxima\Services\Subscriptions\InvoiceDeletion::eligible(), $whereParams) : [0, 0];
            \Faoxima\Admin\Web\Support\BulkDelete::fx_filter_delete_redirect('invoice.php', $invFilterParams, (int)$fdRes[0], (int)$fdRes[1], '', !empty($fdRes[2]));
        }

        if (!empty($_POST['action']) && $_POST['action'] === 'bulk_delete') {
            $requestedIds = $_POST['ids'] ?? [];
            $requestedIds = is_array($requestedIds) ? $requestedIds : [];
            $invBulkKeep = $invFilterParams;
            if (isset($_GET['p']) && is_string($_GET['p']) && ctype_digit($_GET['p'])) $invBulkKeep['p'] = $_GET['p'];
            $invBulkFailed = false;
            $deletedCount = 0;
            try {
                $deletedCount = \Faoxima\Services\Subscriptions\InvoiceDeletion::selected($pdo, $requestedIds);
            } catch (\Throwable $e) {
                \Faoxima\Admin\Web\Support\BulkDelete::fx_delete_log_error('bulk_delete', 'invoice', $e);
                $invBulkFailed = true;
            }
            \Faoxima\Admin\Web\Support\BulkDelete::fx_bulk_delete_redirect('invoice.php', count($requestedIds), $deletedCount, '', $invBulkKeep, $invBulkFailed);
        }

        $pg = \Faoxima\Admin\Web\Support\Pagination::fx_paginate($pdo, "SELECT COUNT(*) FROM invoice WHERE $whereSql", $whereParams, 5);

        $query = $pdo->prepare("SELECT * FROM invoice WHERE $whereSql ORDER BY id_invoice DESC LIMIT :perPage OFFSET :offset");
        foreach ($whereParams as $k => $v) $query->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(':perPage', $pg['perPage'], PDO::PARAM_INT);
        $query->bindValue(':offset', $pg['offset'], PDO::PARAM_INT);
        $query->execute();
        $listinvoice = $query->fetchAll();

        return true;
    }
}
