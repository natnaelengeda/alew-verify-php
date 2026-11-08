<?php

declare(strict_types=1);

namespace App\Data;

use App\Enum\VerificationType;

readonly class StoreVerificationData
{
    public function __construct(
        public VerificationType $type,
        public string $transactionId,
    ) {}
}
