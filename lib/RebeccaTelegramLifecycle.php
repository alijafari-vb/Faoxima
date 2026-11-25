<?php

require_once __DIR__ . '/TelegramTransport.php';

final class FaoximaRebeccaTelegramLifecycle
{
    private const MARKER = "faoxima-rebecca-ready-v1\n";

    private string $baseUrl;
    private CurlHandle $probe;
    private int $probeUntil = 0;
    private bool $ready = false;
    private int $nextWebhookRemoval = 0;

    public function __construct(string $domain)
    {
        $this->baseUrl = 'https://' . rtrim($domain, '/');
        $parts = parse_url($this->baseUrl);
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment'])
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('Set the Faoxima public HTTPS host and path in config.php before using Rebecca mode.');
        }
        $resolve = $parts['host'] . ':' . ($parts['port'] ?? 443) . ':127.0.0.1';
        $this->probe = curl_init($this->baseUrl . '/app/telegram-health.txt');
        curl_setopt_array($this->probe, [
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 600,
            CURLOPT_TIMEOUT_MS => 1200,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
        ]);
    }

    public function isReady(bool $refresh = false): bool
    {
        if ($refresh || hrtime(true) >= $this->probeUntil) {
            $body = curl_exec($this->probe);
            $this->ready = curl_getinfo($this->probe, CURLINFO_RESPONSE_CODE) === 200 && $body === self::MARKER;
            $this->probeUntil = hrtime(true) + 2000000000;
        }
        return $this->ready;
    }

    public function recoverWebhook(string $token, string $proxy, CurlHandle $handle, callable $stillEnabled): array
    {
        $info = FaoximaTelegramTransport::control($token, 'getWebhookInfo', [], 8, $handle, $proxy);
        if (($info['body']['ok'] ?? false) !== true || $info['code'] !== 200) {
            return self::controlFailure($info);
        }
        $url = $info['body']['result']['url'] ?? null;
        if ($url === '') {
            return ['state' => 'conflict', 'delay' => 15];
        }
        if ($url !== $this->baseUrl . '/index.php') {
            return ['state' => 'unexpected_webhook', 'delay' => 30];
        }

        $remaining = $this->nextWebhookRemoval - hrtime(true);
        if ($remaining > 0) {
            return ['state' => 'rebecca_webhook', 'delay' => (int) ceil($remaining / 1000000000)];
        }
        if (!$stillEnabled() || !$this->isReady(true)) {
            return ['state' => 'app_paused', 'delay' => 2];
        }

        $this->nextWebhookRemoval = hrtime(true) + 15000000000;
        $removal = FaoximaTelegramTransport::control(
            $token, 'deleteWebhook', ['drop_pending_updates' => 'false'], 8, $handle, $proxy
        );
        if (($removal['body']['ok'] ?? false) !== true || $removal['code'] !== 200) {
            return self::controlFailure($removal);
        }
        return ['state' => 'recovered', 'delay' => 0];
    }

    private static function controlFailure(array $result): array
    {
        $code = (int) ($result['body']['error_code'] ?? $result['code']);
        if ($code === 429) {
            return ['state' => 'rate_limited', 'delay' => max(1, (int) ($result['body']['parameters']['retry_after'] ?? 5))];
        }
        return ['state' => 'rebecca_webhook', 'delay' => 15];
    }
}
