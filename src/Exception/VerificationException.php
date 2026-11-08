<?php

declare(strict_types=1);

namespace App\Exception;

use Exception;

class VerificationException extends Exception
{
    /** @var array<string, list<string>>|null */
    public ?array $errors = null;

    public function __construct(
        string $message,
        private readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonPayload(): array
    {
        $payload = [
            'status' => 'failed',
            'message' => $this->getMessage(),
        ];

        if ($this->errors !== null) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }

    public static function notFound(string $message = 'Transaction could not be verified.'): self
    {
        return new self($message, 404);
    }

    public static function providerUnavailable(string $message = 'Payment provider is temporarily unavailable.'): self
    {
        return new self($message, 502);
    }

    public static function failed(string $message = 'Unable to complete verification.'): self
    {
        return new self($message, 504);
    }

    public static function tooManyRequests(): self
    {
        return new self('Too many requests.', 429);
    }

    public static function unauthorized(): self
    {
        return new self('Unauthorized.', 401);
    }

    public static function notConfigured(): self
    {
        return new self('API authentication is not configured.', 503);
    }
}
