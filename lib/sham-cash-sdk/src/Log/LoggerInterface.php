<?php

declare(strict_types=1);

namespace OkToCode\ShamCash\Log;

/**
 * Minimal logger contract so the SDK does not need psr/log on WordPress sites.
 */
interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;
}
