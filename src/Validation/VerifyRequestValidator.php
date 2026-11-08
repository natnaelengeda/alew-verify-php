<?php

declare(strict_types=1);

namespace App\Validation;

use App\Data\StoreVerificationData;
use App\Enum\VerificationType;
use App\Exception\VerificationException;

final class VerifyRequestValidator
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public static function validate(?array $body): StoreVerificationData
    {
        if ($body === null) {
            throw self::validationFailed(['body' => ['The request body must be a JSON object.']]);
        }

        $errors = [];

        if (! array_key_exists('type', $body)) {
            $errors['type'] = ['The type field is required.'];
        } else {
            $typeRaw = $body['type'];
            if (! is_string($typeRaw)) {
                $errors['type'] = ['The type field must be a string.'];
            } elseif (VerificationType::tryFromString($typeRaw) === null) {
                $errors['type'] = ['The selected type is invalid.'];
            }
        }

        if (! array_key_exists('transactionId', $body)) {
            $errors['transactionId'] = ['The transaction id field is required.'];
        } else {
            $tx = $body['transactionId'];
            if (! is_string($tx)) {
                $errors['transactionId'] = ['The transaction id field must be a string.'];
            } elseif ($tx === '') {
                $errors['transactionId'] = ['The transaction id field is required.'];
            } elseif (strlen($tx) > 255) {
                $errors['transactionId'] = ['The transaction id field must not be greater than 255 characters.'];
            }
        }

        if ($errors !== []) {
            throw self::validationFailed($errors);
        }

        $type = VerificationType::tryFromString((string) $body['type']);
        assert($type !== null);

        return new StoreVerificationData(
            type: $type,
            transactionId: (string) $body['transactionId'],
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function validationFailed(array $errors): VerificationException
    {
        $first = reset($errors);
        $message = is_array($first) && isset($first[0]) ? (string) $first[0] : 'Validation failed.';

        $exception = new VerificationException($message, 422);
        $exception->errors = $errors;

        return $exception;
    }
}
