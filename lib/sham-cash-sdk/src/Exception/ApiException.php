<?php

declare(strict_types=1);

namespace OkToCode\ShamCash\Exception;

use OkToCode\ShamCash\Enum\ResultCode;

final class ApiException extends ShamCashException
{
    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        public readonly int $resultCode,
        public readonly ?ResultCode $result,
        public readonly string $apiMessage,
        public readonly array $responseBody,
    ) {
        parent::__construct(
            sprintf('ShamCash request failed (%d): %s', $resultCode, $apiMessage),
            $resultCode,
        );
    }

    public function isBillNoAlreadyExists(): bool
    {
        return $this->result === ResultCode::BillNoAlreadyExists;
    }

    public function isBillNotFound(): bool
    {
        return $this->result === ResultCode::BillNotFound;
    }

    public function isBalanceNotEnough(): bool
    {
        return $this->result === ResultCode::BalanceNotEnough;
    }

    public function isRefundExceedsBalance(): bool
    {
        return $this->result === ResultCode::RefundExceedsBalance;
    }

    public function isAgentKeyInvalid(): bool
    {
        return $this->result === ResultCode::AgentKeyInvalid;
    }

    public function isEncryptedDataInvalid(): bool
    {
        return $this->result === ResultCode::EncryptedDataInvalid;
    }
}
