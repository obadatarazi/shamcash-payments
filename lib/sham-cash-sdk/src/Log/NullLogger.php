<?php

declare(strict_types=1);

namespace OkToCode\ShamCash\Log;

final class NullLogger implements LoggerInterface
{
    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }
}
