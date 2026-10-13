<?php

declare(strict_types=1);

namespace OkToCode\ShamCash\Http;

use JsonException;
use OkToCode\ShamCash\Client;
use OkToCode\ShamCash\Enum\ResultCode;
use OkToCode\ShamCash\Exception\ApiException;
use OkToCode\ShamCash\Exception\TransportException;
use OkToCode\ShamCash\Log\LoggerInterface;
use OkToCode\ShamCash\Support\Json;

/**
 * WordPress HTTP API transport (wp_remote_post). No Guzzle / PSR-7 required.
 *
 * @internal
 */
final class Transport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $agentKey,
        private readonly LoggerInterface $logger,
        private readonly float $connectTimeoutSeconds = 5.0,
        private readonly float $requestTimeoutSeconds = 30.0,
    ) {
    }

    /**
     * @return array{raw: array<string, mixed>, preserved: array<string, mixed>}
     */
    public function post(string $operation, string $action, string $encData): array
    {
        if (!function_exists('wp_remote_post')) {
            throw new TransportException('WordPress HTTP API is not available.');
        }

        $url  = $this->baseUrl . '/api/ElectronicPayment/' . $action;
        $body = Json::encode([
            'encData'  => $encData,
            'agentKey' => $this->agentKey,
        ]);

        $timeout = (int) max(1, (int) ceil($this->requestTimeoutSeconds));
        $response = wp_remote_post($url, [
            'timeout'   => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'headers'   => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent'   => Client::USER_AGENT,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->log($operation, null, null, true);

            throw new TransportException(
                'ShamCash request could not be completed: ' . $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $this->log($operation, $status, null, true);

            throw new TransportException(
                sprintf('ShamCash returned HTTP %d for %s.', $status, $operation),
                $status,
            );
        }

        try {
            $rawBody   = (string) wp_remote_retrieve_body($response);
            $decoded   = Json::decodeObject($rawBody);
            $preserved = Json::decodeObjectPreservingNumbers($rawBody);
        } catch (JsonException $exception) {
            $this->log($operation, $status, null, true);

            throw new TransportException('ShamCash response was not a JSON object.', $status, $exception);
        }

        if (!is_int($decoded['result'] ?? null) || !is_bool($decoded['succeeded'] ?? null)) {
            $this->log($operation, $status, null, true);

            throw new TransportException('ShamCash response is missing result.', $status);
        }

        $result  = $decoded['result'];
        $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
        $this->log($operation, $status, $result);

        if ($decoded['succeeded'] !== true || $result !== ResultCode::Success->value) {
            throw new ApiException($result, ResultCode::tryFrom($result), $message, $decoded);
        }

        return ['raw' => $decoded, 'preserved' => $preserved];
    }

    private function log(string $operation, ?int $httpStatus, ?int $result, bool $failed = false): void
    {
        $context = [
            'operation'   => $operation,
            'http_status' => $httpStatus,
            'result'      => $result,
        ];

        if ($failed) {
            $this->logger->warning('ShamCash request failed', $context);

            return;
        }

        $this->logger->info('ShamCash request completed', $context);
    }
}
