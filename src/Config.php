<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /**
     * @return array{
     *     api_bearer_token: string,
     *     rate_limit: int,
     *     app_debug: bool,
     *     base_path: string,
     *     cbe: array{base_url: string, app_id: string, app_version: string, cookie: ?string, timeout: int},
     *     telebirr: array{base_url: string, timeout: int, user_agent: string}
     * }
     */
    public static function load(string $basePath): array
    {
        self::loadEnvFile($basePath.'/.env');

        return [
            'api_bearer_token' => (string) (self::env('API_BEARER_TOKEN') ?? ''),
            'rate_limit' => (int) (self::env('API_RATE_LIMIT_PER_MINUTE') ?: 60),
            'app_debug' => filter_var(self::env('APP_DEBUG') ?? 'false', FILTER_VALIDATE_BOOL),
            'base_path' => $basePath,
            'cbe' => [
                'base_url' => (string) (self::env('CBE_BASE_URL') ?: 'https://mb.cbe.com.et'),
                'app_id' => (string) (self::env('CBE_APP_ID') ?: 'd1292e42-7400-49de-a2d3-9731caa4c819'),
                'app_version' => (string) (self::env('CBE_APP_VERSION') ?: '0a01980b-9859-1369-8198-59f403820000'),
                'cookie' => self::optionalString(self::env('CBE_COOKIE')),
                'timeout' => (int) (self::env('CBE_TIMEOUT') ?: 15),
            ],
            'telebirr' => [
                'base_url' => (string) (self::env('TELEBIRR_BASE_URL') ?: 'https://transactioninfo.ethiotelecom.et'),
                'timeout' => (int) (self::env('TELEBIRR_TIMEOUT') ?: 20),
                'user_agent' => (string) (self::env('TELEBIRR_USER_AGENT') ?: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'),
            ],
        ];
    }

    private static function loadEnvFile(string $path): void
    {
        if (! is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (str_ends_with($value, $quote) && strlen($value) >= 2) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($name) === false) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null ? null : (string) $value;
    }

    private static function optionalString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
