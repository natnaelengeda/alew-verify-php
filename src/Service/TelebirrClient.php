<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\VerificationException;

class TelebirrClient
{
    /**
     * @param  array{base_url: string, timeout: int, user_agent: string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly TelebirrReceiptParser $parser,
    ) {}

    /**
     * @return array{creditParty: ?string, debitParty: ?string, date: ?string, amount: ?string}
     */
    public function getReceiptDetails(string $transactionId): array
    {
        $transactionId = $this->normalizeTransactionId($transactionId);
        $url = rtrim($this->config['base_url'], '/').$this->receiptPath($transactionId);

        try {
            [$statusCode, $html] = $this->httpGet($url);
        } catch (\RuntimeException) {
            throw VerificationException::failed();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw VerificationException::providerUnavailable(
                'Telebirr receipt lookup failed. Try again later.',
            );
        }

        if ($html === '') {
            throw VerificationException::notFound('Telebirr returned an empty receipt page.');
        }

        if ($this->looksLikeProviderOutage($html)) {
            throw VerificationException::providerUnavailable(
                'Telebirr receipt service is temporarily unavailable.',
            );
        }

        if ($this->looksLikeErrorPage($html)) {
            throw VerificationException::notFound(
                'Telebirr could not find a receipt for that transaction ID.',
            );
        }

        return $this->parser->parse($html);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'User-Agent: '.$this->config['user_agent'],
            ],
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            throw new \RuntimeException('curl_exec failed');
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$statusCode, (string) $body];
    }

    private function receiptPath(string $transactionId): string
    {
        return '/receipt/'.rawurlencode($transactionId);
    }

    private function normalizeTransactionId(string $transactionId): string
    {
        $transactionId = trim($transactionId);

        if (filter_var($transactionId, FILTER_VALIDATE_URL)) {
            $path = parse_url($transactionId, PHP_URL_PATH);
            $segment = $path ? basename($path) : $transactionId;

            return $segment !== '' && $segment !== '/' ? $segment : $transactionId;
        }

        return $transactionId;
    }

    private function looksLikeProviderOutage(string $html): bool
    {
        $normalized = strtolower($html);

        return str_contains($normalized, 'gateway time-out')
            || str_contains($normalized, '502 bad gateway')
            || str_contains($normalized, '503 service unavailable');
    }

    private function looksLikeErrorPage(string $html): bool
    {
        $needles = [
            'receipt not found',
            'invalid receipt',
            'transaction not found',
            'does not exist',
        ];

        $normalized = strtolower(preg_replace('/\s+/', ' ', strip_tags($html)) ?? $html);

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return ! str_contains(strtolower($html), 'telebirr transaction information')
            && ! str_contains(strtolower($html), 'telebirr receipt');
    }
}
