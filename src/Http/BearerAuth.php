<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\VerificationException;

final class BearerAuth
{
    public static function check(string $configuredToken): void
    {
        if ($configuredToken === '') {
            throw VerificationException::notConfigured();
        }

        $provided = self::extractBearerToken();

        if ($provided === null || $provided === '' || ! hash_equals($configuredToken, $provided)) {
            throw VerificationException::unauthorized();
        }
    }

    private static function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (! is_string($header) || $header === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
