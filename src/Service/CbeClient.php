<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\VerificationException;

class CbeClient
{
    /**
     * @param  array{base_url: string, app_id: string, app_version: string, cookie: ?string, timeout: int}  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getTransactionDetail(string $receiptId): array
    {
        $receiptId = $this->normalizeReceiptId($receiptId);
        $url = rtrim($this->config['base_url'], '/').$this->transactionDetailPath($receiptId);

        try {
            [$statusCode, $body] = $this->httpGet($url);
        } catch (\RuntimeException) {
            throw VerificationException::failed();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw VerificationException::providerUnavailable(
                'CBE transaction lookup failed. Try again later.',
            );
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw VerificationException::providerUnavailable(
                'CBE transaction lookup failed. Try again later.',
            );
        }

        if (! is_array($payload) || empty($payload['id'])) {
            $message = is_array($payload) && isset($payload['detail'])
                ? (string) $payload['detail']
                : 'CBE could not find a transaction for that receipt ID.';

            throw VerificationException::notFound($message);
        }

        return $payload;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function httpGet(string $url): array
    {
        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'en-US,en;q=0.9,am-ET;q=0.8,am;q=0.7',
            'Connection' => 'keep-alive',
            'DNT' => '1',
            'Origin' => 'https://mbreciept.cbe.com.et',
            'Referer' => 'https://mbreciept.cbe.com.et/',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-site',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
            'sec-ch-ua' => '"Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
            'sec-ch-ua-mobile' => '?1',
            'sec-ch-ua-platform' => '"iOS"',
            'x-app-id' => $this->config['app_id'],
            'x-app-version' => $this->config['app_version'],
        ];

        if ($this->config['cookie'] !== null && $this->config['cookie'] !== '') {
            $headers['Cookie'] = $this->config['cookie'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            throw new \RuntimeException('curl_exec failed');
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$statusCode, (string) $body];
    }

    private function transactionDetailPath(string $receiptId): string
    {
        return '/api/v1/transactions/public/transaction-detail/'.rawurlencode($receiptId);
    }

    private function normalizeReceiptId(string $transactionId): string
    {
        $transactionId = trim($transactionId);

        if (filter_var($transactionId, FILTER_VALIDATE_URL)) {
            $path = parse_url($transactionId, PHP_URL_PATH);
            $segment = $path ? basename($path) : $transactionId;

            return $segment !== '' && $segment !== '/' ? $segment : $transactionId;
        }

        return $transactionId;
    }
}
