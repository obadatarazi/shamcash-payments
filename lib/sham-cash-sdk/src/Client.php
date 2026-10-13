<?php

declare(strict_types=1);

namespace OkToCode\ShamCash;

use InvalidArgumentException;
use JsonException;
use OkToCode\ShamCash\Crypto\DirectJwe;
use OkToCode\ShamCash\Enum\Currency;
use OkToCode\ShamCash\Exception\CryptoException;
use OkToCode\ShamCash\Exception\TransportException;
use OkToCode\ShamCash\Http\Transport;
use OkToCode\ShamCash\Log\LoggerInterface;
use OkToCode\ShamCash\Log\NullLogger;
use OkToCode\ShamCash\Model\Bill;
use OkToCode\ShamCash\Model\RefundResult;
use OkToCode\ShamCash\Model\Transaction;
use OkToCode\ShamCash\Model\TransactionPage;
use OkToCode\ShamCash\Model\WebhookNotification;
use OkToCode\ShamCash\Support\Clock;
use OkToCode\ShamCash\Support\Decimal;
use OkToCode\ShamCash\Support\Input;
use OkToCode\ShamCash\Support\Json;
use OkToCode\ShamCash\Support\ResponseMapper;
use OkToCode\ShamCash\Support\SystemClock;

final class Client
{
    public const VERSION = '1.0.2';

    public const USER_AGENT = 'OkToCode-sham-cash-php/' . self::VERSION;

    private readonly ?string $callbackUrl;

    private readonly ?string $redirectUrl;

    private readonly DirectJwe $jwe;

    private readonly Transport $transport;

    private readonly Clock $clock;

    /**
     * callbackUrl is where ShamCash POSTs the encrypted webhook. redirectUrl is where the browser returns after checkout.
     * Both are optional here. createBill() uses the argument on that call when it is passed, otherwise this client value.
     *
     * HTTP uses WordPress wp_remote_post() via Transport — no Guzzle required.
     * The clock argument defaults to the system clock and exists so tests can freeze iat and exp.
     */
    public function __construct(
        string $agentKey,
        string $secretKey,
        string $baseUrl,
        ?string $callbackUrl = null,
        ?string $redirectUrl = null,
        float $connectTimeoutSeconds = 5.0,
        float $requestTimeoutSeconds = 30.0,
        private readonly int $tokenTtlSeconds = 300,
        private readonly int $webhookClockSkewSeconds = 30,
        ?LoggerInterface $logger = null,
        ?Clock $clock = null,
        ?Transport $transport = null,
    ) {
        $agentKey = trim($agentKey);
        $secretKey = trim($secretKey);
        if ($agentKey === '') {
            throw new InvalidArgumentException('agentKey is required.');
        }
        if ($secretKey === '') {
            throw new InvalidArgumentException('secretKey is required.');
        }
        if ($connectTimeoutSeconds <= 0 || $requestTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Timeouts must be greater than 0.');
        }
        if ($this->tokenTtlSeconds <= 0) {
            throw new InvalidArgumentException('Token TTL must be greater than 0.');
        }
        if ($this->webhookClockSkewSeconds < 0) {
            throw new InvalidArgumentException('Webhook clock skew cannot be negative.');
        }

        $this->callbackUrl = $callbackUrl === null ? null : Input::absoluteUrl($callbackUrl);
        $this->redirectUrl = $redirectUrl === null ? null : Input::absoluteUrl($redirectUrl);
        $this->jwe = DirectJwe::fromSecret($secretKey);
        $this->clock = $clock ?? new SystemClock();
        $this->transport = $transport ?? new Transport(
            Input::baseUrl($baseUrl),
            $agentKey,
            $logger ?? new NullLogger(),
            $connectTimeoutSeconds,
            $requestTimeoutSeconds,
        );
    }

    /**
     * Pass callbackUrl or redirectUrl to override the client default for this bill only.
     * ShamCash still requires both URLs. A missing URL throws before the request is sent.
     */
    public function createBill(
        string $billNo,
        string $amount,
        Currency $currency,
        ?string $note = null,
        ?string $callbackUrl = null,
        ?string $redirectUrl = null,
    ): Bill {
        $payload = [
            'billNo' => Input::billNo($billNo),
            'amount' => Decimal::billAmount($amount),
            'currencyId' => $currency->value,
            'callbackUrl' => $this->resolveUrl($callbackUrl, $this->callbackUrl, 'callbackUrl'),
            'redirectUrl' => $this->resolveUrl($redirectUrl, $this->redirectUrl, 'redirectUrl'),
        ];
        $note = Input::note($note);
        if ($note !== null) {
            $payload['note'] = $note;
        }

        $response = $this->transport->post('createBill', 'createBill', $this->encrypt($payload));

        return ResponseMapper::bill($response['raw'], $response['preserved']);
    }

    public function getBill(string $billNo): Bill
    {
        $response = $this->transport->post('getBill', 'getBillInfo', $this->encrypt([
            'billNo' => Input::billNo($billNo),
        ]));

        return ResponseMapper::bill($response['raw'], $response['preserved']);
    }

    /**
     * idempotencyKey is required. Reuse the same key to retry this refund safely.
     */
    public function refundBill(
        string $billNo,
        string $amount,
        string $idempotencyKey,
        ?string $note = null,
    ): RefundResult {
        $payload = [
            'billNo' => Input::billNo($billNo),
            'amount' => Decimal::refundAmount($amount),
            'idempotencyKey' => Input::idempotencyKey($idempotencyKey),
        ];
        $note = Input::note($note);
        if ($note !== null) {
            $payload['note'] = $note;
        }

        $response = $this->transport->post('refundBill', 'refundBill', $this->encrypt($payload));

        return ResponseMapper::refundResult($response['raw'], $response['preserved']);
    }

    public function listTransactions(
        ?string $fromDate = null,
        ?string $toDate = null,
        int $afterTranId = 0,
        int $limit = 500,
    ): TransactionPage {
        $fromDate = $fromDate === null ? null : Input::date($fromDate);
        $toDate = $toDate === null ? null : Input::date($toDate);
        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            throw new InvalidArgumentException('fromDate must be on or before toDate.');
        }

        $payload = [
            'afterTranId' => Input::afterTranId($afterTranId),
            'limit' => Input::limit($limit),
        ];
        if ($fromDate !== null) {
            $payload['fromDate'] = $fromDate;
        }
        if ($toDate !== null) {
            $payload['toDate'] = $toDate;
        }

        $response = $this->transport->post('listTransactions', 'getTransactions', $this->encrypt($payload));

        return ResponseMapper::transactionPage($response['raw'], $response['preserved']);
    }

    /**
     * Follows hasMore and lastReturnedTranId until every matching transaction has been yielded.
     *
     * @return \Generator<int, Transaction>
     */
    public function eachTransaction(
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 500,
    ): \Generator {
        $afterTranId = 0;
        do {
            $page = $this->listTransactions($fromDate, $toDate, $afterTranId, $limit);
            foreach ($page->transactions as $transaction) {
                yield $transaction;
            }

            if (!$page->hasMore) {
                return;
            }

            if ($page->transactions === [] || $page->lastReturnedTranId <= $afterTranId) {
                throw new TransportException('ShamCash pagination cursor did not advance.');
            }

            $afterTranId = $page->lastReturnedTranId;
        } while (true);
    }

    /**
     * Pass the raw webhook body. Do not re-encode the JSON before calling this method.
     */
    public function parseWebhook(string $rawBody): WebhookNotification
    {
        try {
            $envelope = Json::decodeObject($rawBody);
        } catch (JsonException $exception) {
            throw new CryptoException('Webhook body must be a JSON object.', 0, $exception);
        }

        $encData = $envelope['encData'] ?? null;
        if (!is_string($encData) || $encData === '') {
            throw new CryptoException('Webhook body must contain encData.');
        }

        $plaintext = $this->jwe->decrypt($encData);
        try {
            $payload = Json::decodeObjectPreservingNumbers($plaintext);
            $raw = Json::decodeObject($plaintext);
        } catch (JsonException $exception) {
            throw new CryptoException('Webhook payload must be a JSON object.', 0, $exception);
        }

        $notification = ResponseMapper::webhook($payload, $raw);
        $now = $this->clock->now();
        if ($notification->iat > $now + $this->webhookClockSkewSeconds) {
            throw new CryptoException('Webhook token was issued too far in the future.');
        }
        if ($now > $notification->exp + $this->webhookClockSkewSeconds) {
            throw new CryptoException('Webhook token has expired.');
        }

        return $notification;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encrypt(array $payload): string
    {
        $issuedAt = $this->clock->now();
        $payload['iat'] = $issuedAt;
        $payload['exp'] = $issuedAt + $this->tokenTtlSeconds;

        return $this->jwe->encrypt(Json::encode($payload));
    }

    private function resolveUrl(?string $override, ?string $default, string $name): string
    {
        if ($override !== null) {
            return Input::absoluteUrl($override);
        }

        if ($default !== null) {
            return $default;
        }

        throw new InvalidArgumentException(
            $name . ' is required. Set it on the client or pass it to createBill().',
        );
    }
}
