<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\VerificationException;

final class RateLimiter
{
    private const WINDOW_SECONDS = 60;

    public static function check(string $bucket, string $key, int $limit, string $storageDir): void
    {
        if ($limit <= 0) {
            return;
        }

        if (! is_dir($storageDir) && ! mkdir($storageDir, 0755, true) && ! is_dir($storageDir)) {
            return;
        }

        $file = $storageDir.'/'.hash('sha256', $bucket.'|'.$key).'.json';
        $now = time();
        $data = ['reset' => $now + self::WINDOW_SECONDS, 'count' => 0];

        if (is_readable($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['reset'], $decoded['count'])) {
                    $data = [
                        'reset' => (int) $decoded['reset'],
                        'count' => (int) $decoded['count'],
                    ];
                }
            }
        }

        if ($now > $data['reset']) {
            $data = ['reset' => $now + self::WINDOW_SECONDS, 'count' => 0];
        }

        $data['count']++;

        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);

        if ($data['count'] > $limit) {
            throw VerificationException::tooManyRequests();
        }
    }
}
