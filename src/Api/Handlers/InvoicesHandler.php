<?php

declare(strict_types=1);

namespace Faoxima\Api\Handlers;

use ManagePanel;
use Throwable;

final class InvoicesHandler extends \Faoxima\Api\Handlers\BaseHandler
{
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->requireMethod('GET');
        }
        $db = \Faoxima\Api\Runtime\Db::pdo();
        $limit = \Faoxima\Api\Runtime\Input::intRange($this->data, 'limit', 1, 10, 10);
        $page = \Faoxima\Api\Runtime\Input::intRange($this->data, 'page', 1, 100000, 1);
        $search = \Faoxima\Api\Runtime\Input::nullableString($this->data, 'q');
        $all = ($this->data['scope'] ?? '') === 'all';
        $canSearchAll = (bool) \Faoxima\Api\Runtime\Db::fetchScalar(
            "SELECT 1 FROM admin WHERE id_admin = :id AND rule = 'administrator' LIMIT 1",
            [':id' => $this->user['id']]
        );
        if ($all && !$canSearchAll) {
            \Faoxima\Api\Runtime\Response::forbidden();
        }
        if (($all && $search === null) || ($search !== null && $method !== 'POST' && \Faoxima\Services\Subscriptions\ServiceSearch::isPrivate($search))) {
            \Faoxima\Api\Runtime\Response::badRequest('عبارت جستجو را از فرم اشتراک‌ها بفرستید.');
        }
        $where = $all ? \Faoxima\Services\Subscriptions\ServiceSearch::VISIBLE_STATUS : 'id_user = :user_id AND ' . \Faoxima\Services\Subscriptions\ServiceSearch::VISIBLE_STATUS;
        $params = $all ? [] : [':user_id' => $this->user['id']];
        if ($search !== null) {
            [$condition, $searchParams] = \Faoxima\Services\Subscriptions\ServiceSearch::condition($db, $search, $where, $params);
            $where .= ' AND ' . $condition;
            $params += $searchParams;
        }
        $totalItems = (int) \Faoxima\Api\Runtime\Db::fetchScalar("SELECT COUNT(*) FROM invoice WHERE $where", $params);
        $totalPages = (int) ceil($totalItems / $limit);
        $page = min($page, max(1, $totalPages));
        $sort = \Faoxima\Api\Runtime\Input::nullableString($this->data, 'sort');
        $sort = in_array($sort, \Faoxima\Services\Subscriptions\ServiceSearch::TABLE_SORTS, true) && !$all ? $sort : 'recent';
        $ascending = ($this->data['direction'] ?? null) === 'asc';
        $rows = \Faoxima\Services\Subscriptions\ServiceSearch::invoicePage($db, $where, $params, $limit, ($page - 1) * $limit, $sort, $ascending,
            columns: $all ? 'id_invoice, username, Service_location, Status' : '*', liveUsage: !$all && $totalItems <= 50);
        if ($all) {
            \Faoxima\Api\Runtime\Response::ok([
                'items' => $rows, 'total' => $totalItems, 'total_pages' => $totalPages,
                'page' => $page, 'limit' => $limit, 'can_search_all' => true,
            ]);
        }

        $queuedUsernames = [];
        if (!empty($rows)) {
            $usernames = array_values(array_unique(array_map(static fn($r) => (string)$r['username'], $rows)));
            $placeholders = [];
            $queuedParams = [];
            foreach ($usernames as $i => $uname) {
                $ph = ":qu{$i}";
                $placeholders[] = $ph;
                $queuedParams[$ph] = $uname;
            }
            $queuedRows = \Faoxima\Api\Runtime\Db::fetchAll(
                "SELECT DISTINCT username FROM queued_renewal WHERE status IN (" . \Faoxima\Services\Renewal\ReservationStore::unresolvedSql() . ") AND username IN (" . implode(',', $placeholders) . ")",
                $queuedParams
            );
            foreach ($queuedRows as $qr) {
                $queuedUsernames[(string)$qr['username']] = true;
            }
        }

        foreach ($rows as &$row) {
            $cached = json_decode((string) ($row['user_info'] ?? ''), true);
            $panelUsage = $row['panel_usage'] ?? null;
            $remote = $panelUsage ?? (is_array($cached) ? $cached : []);
            $localStatus = $row['Status'] ?? 'active';
            $soldAt = (int) ($row['time_sell'] ?? 0);
            $duration = (int) ($row['Service_time'] ?? 0);
            $unit = ($row['name_product'] ?? '') === 'سرویس تست' ? 3600 : 86400;
            if ($panelUsage === null && in_array($localStatus, ['active', 'sendedwarn'], true)
                && $soldAt > 0 && $duration > 0 && $soldAt + $duration * $unit <= time()
                && (!is_numeric($remote['expire'] ?? null) || (int) $remote['expire'] <= time())) {
                try {
                    $panel = new ManagePanel();
                    $live = $panel->DataUser($row['Service_location'], $row['username'], 'links', (string) $row['id_invoice']);
                    if (is_array($live) && !empty($live['status']) && $live['status'] !== 'Unsuccessful') {
                        $remote = $live;
                        \Faoxima\Core\Database\Db::update('invoice', 'user_info', json_encode($live, JSON_UNESCAPED_UNICODE), 'id_invoice', $row['id_invoice']);
                    }
                } catch (Throwable $e) {
                    // Keep the last known state when the panel is temporarily unavailable.
                }
            }
            $row['status'] = in_array($localStatus, ['disabled', 'disablebyadmin'], true) ? $localStatus : match ($remote['status'] ?? '') {
                'expired' => 'end_of_time', 'limited' => 'end_of_volume', 'on_hold' => 'send_on_hold',
                'active' => $localStatus === 'sendedwarn' ? 'sendedwarn' : 'active',
                'disabled' => 'disabled', default => $localStatus,
            };
            $row['panel_usage'] = $remote;
            $row = array_replace($row, \Faoxima\Services\Subscriptions\ServiceSearch::usage($row, time()));
            unset($row['panel_usage']);
            $row['plan_label'] = \Faoxima\Services\Subscriptions\PlanLabel::display($row);
            $row['has_queued_renewal'] = isset($queuedUsernames[(string)$row['username']]);
            $row['display_username'] = \Faoxima\Services\Panels\Drivers\Guard::guardDisplayUsername((string)$row['username'], (string)($row['Service_location'] ?? ''));
        }
        unset($row);


        \Faoxima\Api\Runtime\Response::ok([
            'items'       => $rows,
            'total'       => $totalItems,
            'total_pages' => $totalPages,
            'page'        => $page,
            'limit'       => $limit,
            'can_search_all' => $canSearchAll,
            'cached_usage' => $totalItems > 50,
        ]);
    }
}

