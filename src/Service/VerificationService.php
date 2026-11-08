<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\StoreVerificationData;
use App\Enum\VerificationType;

class VerificationService
{
    public function __construct(
        private readonly CbeClient $cbeClient,
        private readonly TelebirrClient $telebirrClient,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(StoreVerificationData $data): array
    {
        return match ($data->type) {
            VerificationType::Telebirr => $this->verifyTelebirr($data->transactionId),
            VerificationType::Cbe => $this->verifyCbe($data->transactionId),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function verifyTelebirr(string $transactionId): array
    {
        $receipt = $this->telebirrClient->getReceiptDetails($transactionId);

        return [
            'type' => VerificationType::Telebirr->value,
            'transactionId' => $transactionId,
            'creditParty' => $receipt['creditParty'],
            'debitParty' => $receipt['debitParty'],
            'date' => $receipt['date'],
            'amount' => $receipt['amount'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function verifyCbe(string $transactionId): array
    {
        $transaction = $this->cbeClient->getTransactionDetail($transactionId);

        return [
            'type' => VerificationType::Cbe->value,
            'transactionId' => $transactionId,
            'creditParty' => $transaction['creditAccountHolder'] ?? null,
            'debitParty' => $transaction['debitAccountHolder'] ?? null,
            'date' => $this->resolveCbeTransactionDate($transaction),
            'amount' => $transaction['amountCreditedWithCurrency']
                ?? $this->formatCbeAmount(
                    $transaction['creditCurrency'] ?? null,
                    $transaction['amountCredited'] ?? null,
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function resolveCbeTransactionDate(array $transaction): ?string
    {
        $dateTimes = $transaction['dateTimes'] ?? null;

        if (is_array($dateTimes) && isset($dateTimes[0]) && is_string($dateTimes[0])) {
            return $dateTimes[0];
        }

        $processingDate = $transaction['processingDate'] ?? null;

        return is_string($processingDate) && $processingDate !== ''
            ? $processingDate
            : null;
    }

    private function formatCbeAmount(?string $currency, ?string $amount): ?string
    {
        if ($amount === null || $amount === '' || $currency === null || $currency === '') {
            return null;
        }

        return $currency.$amount;
    }
}
