<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('FAOXIMA_LAZY_MYSQLI', true);
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/TelegramInbox.php';
require dirname(__DIR__) . '/lib/WebhookAuth.php';

function runTelegramPoller(FaoximaTelegramInbox $inbox, PDO $db, string $token): void
{
    $handle = curl_init();
    $offset = 0;
    $revision = null;
    $failures = 0;
    $lastState = null;

    while (true) {
        $settings = FaoximaTelegramTransport::settings($db);
        if (!$settings || !$settings['polling']) {
            $revision = null;
            sleep(2);
            continue;
        }
        $firstRequest = $revision !== (int) $settings['revision'];
        if ($firstRequest) {
            $revision = (int) $settings['revision'];
            $offset = 0;
            $failures = 0;
            $lastState = null;
        }

        $timeout = $firstRequest || $failures > 0 ? 0 : 25;
        $result = FaoximaTelegramTransport::control(
            $token, 'getUpdates', ['timeout' => $timeout, 'offset' => $offset],
            $timeout + 10, $handle, $settings['proxy_url']
        );
        $body = $result['body'];
        if ($result['errno'] === 0 && $result['code'] === 200 && ($body['ok'] ?? false) === true
            && isset($body['result']) && is_array($body['result']) && array_is_list($body['result'])) {
            // Telegram confirms the previous batch on the following request with offset.
            // Send that offset only after committing every update in the batch.
            $offset = $inbox->store($body['result']);
            $inbox->pollStatus($revision, 'online');
            if ($lastState !== 'online') {
                fwrite(STDERR, "Telegram polling is online.\n");
            }
            $lastState = 'online';
            $failures = 0;
            continue;
        }

        // A later recovery can start a new Telegram update-ID sequence.
        // Re-fetch any unconfirmed updates instead of reusing a stale offset.
        $offset = 0;
        $code = (int) ($body['error_code'] ?? $result['code']);
        $state = match ($code) {
            401, 404 => 'token_error',
            409 => 'conflict',
            429 => 'rate_limited',
            default => 'unreachable',
        };
        if ($lastState !== $state) {
            $inbox->pollStatus($revision, $state);
            fwrite(STDERR, "Telegram polling: {$state} (API {$code}, cURL {$result['errno']}).\n");
        }
        $lastState = $state;
        $failures = min($failures + 1, 6);
        $delay = match ($state) {
            'conflict', 'token_error' => 15,
            'rate_limited' => max(1, (int) ($body['parameters']['retry_after'] ?? 5)),
            default => min(20, 2 ** ($failures - 1)),
        };
        usleep($delay * 1000000 + random_int(0, 250000));
    }
}

function runTelegramUpdate(string $botId, array $job, int $workerConnection): int
{
    $input = tmpfile();
    if ($input === false) {
        return 75;
    }
    try {
        $payload = $job['payload'];
        if (fwrite($input, $payload) !== strlen($payload) || !rewind($input)) {
            return 75;
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/telegram-update.php', $botId, (string) $job['seq'], (string) $workerConnection],
            [0 => $input, 1 => ['file', '/dev/null', 'w'], 2 => STDERR],
            $pipes,
            dirname(__DIR__)
        );
        if (!is_resource($process)) {
            return 75;
        }

        $deadline = hrtime(true) + 120000000000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);
                return $status['exitcode'];
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);

        proc_terminate($process, 9);
        proc_close($process);
        return 1;
    } finally {
        fclose($input);
    }
}

function runTelegramWorker(FaoximaTelegramInbox $inbox, PDO $db, string $botId, bool $drain = false): void
{
    $inbox->recoverInterrupted();
    $workerConnection = (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn();
    $nextPrune = 0;
    while (true) {
        if (time() >= $nextPrune) {
            $pruned = $inbox->prune();
            $nextPrune = time() + ($pruned === 1000 ? 10 : 3600);
        }
        $settings = FaoximaTelegramTransport::settings($db);
        if (!$settings || !$settings['polling'] || (!$drain && time() - (int) $settings['last_poll_ok'] > 60)) {
            if ($drain) {
                throw new RuntimeException('Long polling must remain enabled while draining the inbox.');
            }
            usleep(500000);
            continue;
        }
        $job = $inbox->next();
        if ($job === null) {
            if ($drain) {
                if ($inbox->counts() !== []) {
                    throw new RuntimeException('The inbox has rows that require review.');
                }
                return;
            }
            usleep(100000);
            continue;
        }
        if (!$inbox->transition((int) $job['seq'], 'pending', 'running')) {
            continue;
        }
        $start = hrtime(true);
        $exit = runTelegramUpdate($botId, $job, $workerConnection);
        $state = match ($exit) {
            0 => 'done',
            75 => 'pending',
            default => 'held',
        };
        $inbox->transition((int) $job['seq'], 'running', $state);
        $ms = (int) ((hrtime(true) - $start) / 1000000);
        if ($exit !== 0 || $ms >= 1000) {
            fwrite(STDERR, "Telegram update {$job['update_id']} {$state} after {$ms} ms.\n");
        }
        if ($drain && $exit !== 0) {
            throw new RuntimeException('Drain stopped after a dispatch failure. Check the inbox.');
        }
        if ($exit === 75) {
            sleep(2);
        }
    }
}

try {
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Telegram service requires the Faoxima database.');
    }
    $token = (string) $APIKEY;
    $botId = FaoximaTelegramInbox::botId($token);
    $inbox = new FaoximaTelegramInbox($pdo, $botId);
    $role = $argv[1] ?? '';
    if ($role === 'init') {
        $inbox->install();
        exit(0);
    }
    if ($role === 'status') {
        $settings = FaoximaTelegramTransport::settings($pdo);
        echo json_encode([
            'polling' => !empty($settings['polling']),
            'state' => $settings['poll_state'] ?? 'idle',
            'counts' => $settings === null ? [] : $inbox->counts(),
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    if (in_array($role, ['detach-webhook', 'attach-webhook', 'sync-webhook'], true)) {
        $settings = FaoximaTelegramTransport::settings($pdo);
        if ($role === 'sync-webhook' && !empty($settings['polling'])) {
            fwrite(STDOUT, "Telegram polling is enabled. Webhook synchronization skipped.\n");
            exit(0);
        }
        $proxy = $settings['proxy_url'] ?? '';
        $detach = $role === 'detach-webhook';
        if ($detach !== !empty($settings['polling'])) {
            fwrite(STDERR, $detach
                ? "Enable polling in the admin panel before detaching the webhook.\n"
                : "Disable polling in the admin panel before attaching the webhook.\n");
            exit(1);
        }
        $parameters = ['drop_pending_updates' => 'false'];
        if (!$detach) {
            if ($settings !== null && $inbox->counts() !== []) {
                fwrite(STDERR, "Drain or review the local Telegram inbox before attaching the webhook.\n");
                exit(1);
            }
            $url = $role === 'sync-webhook' ? ($argv[2] ?? '') : ('https://' . $domainhosts . '/index.php');
            if (!str_starts_with($url, 'https://') || !filter_var($url, FILTER_VALIDATE_URL)) {
                fwrite(STDERR, "The Faoxima webhook URL is invalid. Check the domain in config.php.\n");
                exit(1);
            }
            $parameters['url'] = $url;
            $parameters['secret_token'] = FaoximaWebhookAuth::secret($token);
        }
        $method = $detach ? 'deleteWebhook' : 'setWebhook';
        $result = FaoximaTelegramTransport::control($token, $method, $parameters, 10, null, $proxy);
        if (($result['body']['ok'] ?? false) !== true) {
            fwrite(STDERR, "Telegram rejected {$method} (HTTP {$result['code']}, cURL {$result['errno']}).\n");
            exit(1);
        }
        fwrite(STDOUT, "Telegram confirmed {$method}. Pending updates were kept.\n");
        exit(0);
    }
    if ($role === 'retry' || $role === 'resolve') {
        $seq = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $target = $role === 'retry' ? 'pending' : 'done';
        if ($seq === false || !$inbox->transition($seq, 'held', $target)) {
            fwrite(STDERR, "Provide a row ID currently held for review.\n");
            exit(1);
        }
        fwrite(STDOUT, "Update moved to {$target}.\n");
        exit(0);
    }
    if ($role === 'drain') {
        $settings = FaoximaTelegramTransport::settings($pdo);
        if (empty($settings['polling']) || !$inbox->lock('poll') || !$inbox->lock('work', 5)) {
            throw new RuntimeException('Stop the polling and worker services and keep polling enabled in the panel.');
        }
        $probe = FaoximaTelegramTransport::control($token, 'getMe', [], 10, null, $settings['proxy_url']);
        if (($probe['body']['ok'] ?? false) !== true) {
            throw new RuntimeException('The Telegram proxy must be reachable to drain the inbox.');
        }
        runTelegramWorker($inbox, $pdo, $botId, true);
        fwrite(STDOUT, "The local Telegram inbox is empty.\n");
        exit(0);
    }
    if (!in_array($role, ['poll', 'work'], true)) {
        fwrite(STDERR, "Usage: php scripts/telegram-polling.php init|poll|work|status|drain|detach-webhook|attach-webhook|sync-webhook URL|retry ROW|resolve ROW\n");
        exit(2);
    }
    if (!$inbox->lock($role, $role === 'work' ? 5 : 0)) {
        fwrite(STDERR, "Another Telegram process already owns the {$role} lock.\n");
        exit(1);
    }
    if ($role === 'poll') {
        runTelegramPoller($inbox, $pdo, $token);
    } else {
        runTelegramWorker($inbox, $pdo, $botId);
    }
} catch (Throwable $error) {
    $message = get_class($error) === RuntimeException::class
        ? $error->getMessage()
        : 'Telegram service stopped (' . get_class($error) . ', code ' . $error->getCode() . '). Check configuration and database.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
