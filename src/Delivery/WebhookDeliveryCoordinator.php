<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Qiick\Outbound\Enums\WebhookAttemptStatusEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\OutboundRequest;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Retry\RetryAfter;
use Qiick\Outbound\Retry\RetryPolicy;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Webhooks\SignedWebhook;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookMessage;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;
use Qiick\Outbound\Webhooks\WebhookSigningSet;

/**
 * Fans an event out to the subscribed endpoints and sends the due attempts.
 *
 * {@see dispatch()} stores one delivery per endpoint with the exact body every
 * attempt signs. {@see work()} (the `outbound:webhooks:work` command, run by
 * the scheduler) leases due deliveries, signs each attempt with the
 * endpoint's signing set and a fresh `webhook-timestamp`, and POSTs it through
 * the package's {@see PinnedHttpClient} only: SSRF guard, pinned address, no
 * proxy, no redirect. Retries are driven by `next_attempt_at`, not by queue
 * delays, so a retry 24 h away survives queue loss (standards review M9).
 *
 * Every non-2xx answer, timeout and connection error is a failure retried on
 * the schedule ({@see RetryPolicy}); a refusal by the guard or the signer is
 * recorded as `refused` and retried the same way, since a DNS answer or a
 * missing secret can change. Each attempt moves the endpoint's failure streak.
 *
 * @api
 */
final class WebhookDeliveryCoordinator
{
    /**
     * @param  int  $timeoutSeconds  `outbound.webhooks.timeout_seconds` (15)
     * @param  int  $connectTimeoutSeconds  `outbound.http.connect_timeout_seconds`
     * @param  int|null  $maxResponseBytes  `outbound.http.max_response_bytes`
     * @param  (Closure(): int)|null  $nanosecondClock  monotonic clock for `duration_ms`
     */
    public function __construct(
        private readonly DatabaseDeliveryStore $store,
        private readonly WebhookSecretResolver $secretResolver,
        private readonly StandardWebhookSigner $signer,
        private readonly PinnedHttpClient $client,
        private readonly RetryPolicy $retryPolicy,
        private readonly OutboundHttpLimits $limits,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly WebhookRetryPresetEnum $defaultRetryPreset,
        private readonly int $defaultMaxConcurrency,
        private readonly ?int $maxResponseBytes,
        private readonly ?Closure $nanosecondClock = null,
    ) {}

    /**
     * Store one pending delivery, due now, for every active endpoint in the
     * scope subscribed to the message type (or to every type). Dispatching
     * the same message again creates nothing: `webhook-id` is unique per
     * endpoint.
     *
     * @param  string|null  $scope  the value of the product's scope column;
     *                              null for instance-level endpoints
     * @return Collection<int, WebhookDelivery> the deliveries created
     *
     * @throws InvalidArgumentException when a scope is given without a scope column
     * @throws WebhookException when the message cannot be encoded
     * @throws WebhookDeliveryStorageException when a row cannot be written
     */
    public function dispatch(WebhookMessage $message, ?string $scope = null): Collection
    {
        $rawBody = $message->toPayloadJson();
        $createdDeliveries = new Collection;

        foreach ($this->store->subscribedEndpoints($message->type, $scope) as $endpoint) {
            $maxAttempts = $endpoint->max_attempts ?? count($this->retryDelaysFor($endpoint)) + 1;
            $delivery = $this->store->createDelivery($endpoint, $message, $rawBody, $maxAttempts);

            if ($delivery !== null) {
                $createdDeliveries->push($delivery);
            }
        }

        return $createdDeliveries;
    }

    /**
     * Lease up to `$limit` due deliveries and send one attempt for each.
     *
     * Side effects: outbound HTTP POSTs; attempt rows; delivery and endpoint updates.
     *
     * @return int the attempts sent or refused
     *
     * @throws InvalidArgumentException when `$limit` is below 1
     */
    public function work(int $limit): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The work limit must be at least 1.');
        }

        // The lease outlives the longest possible send: the whole redirect-chain deadline.
        $claimedDeliveries = $this->store->claimDue($limit, $this->limits->totalDeadlineSeconds + 60, $this->defaultMaxConcurrency);

        foreach ($claimedDeliveries as $delivery) {
            $this->attempt($delivery);
        }

        return count($claimedDeliveries);
    }

    private function attempt(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        $attemptNumber = $delivery->attempts_count + 1;
        $attemptedAt = CarbonImmutable::now('UTC');
        $startedAt = $this->nanoseconds();
        $outcome = $this->send($delivery, $endpoint, $attemptedAt->getTimestamp());
        $durationMilliseconds = intdiv($this->nanoseconds() - $startedAt, 1_000_000);

        $response = $outcome['response'];
        $httpStatus = $response?->status();
        // A missing header reads as '', which parses to null.
        $retryAfterSeconds = $response === null ? null : RetryAfter::parse($response->header('Retry-After'), $attemptedAt);
        $isSuccess = $outcome['status'] === WebhookAttemptStatusEnum::Delivered;
        $deliveryChanges = $isSuccess
            ? ['status' => DeliveryStatus::Delivered, 'delivered_at' => $attemptedAt, 'next_attempt_at' => null]
            : $this->scheduleAfterFailure($delivery, $endpoint, $attemptNumber, $httpStatus, $retryAfterSeconds, $attemptedAt);
        $nextAttemptAt = $deliveryChanges['next_attempt_at'];

        $errorMessage = DeliveryAttempt::sanitiseErrorMessage($outcome['errorMessage']);

        $this->store->recordAttempt($delivery, [
            // `trigger` keeps the table default, `schedule`.
            'attempt' => $attemptNumber,
            'status' => $outcome['status'],
            'webhook_timestamp' => $attemptedAt->getTimestamp(),
            'signature_schemes' => 'v1',
            'secret_public_ids' => $outcome['secretPublicIds'],
            'http_status' => $httpStatus,
            'error_code' => $outcome['errorCode'],
            'error_message' => $errorMessage,
            'retry_after_seconds' => $retryAfterSeconds,
            'response_headers' => $response === null ? null : $this->keptResponseHeaders($response),
            'response_bytes' => $response === null ? null : strlen($response->body()),
            'duration_ms' => $durationMilliseconds,
            'request_id' => $response === null ? null : (substr($response->header('X-Request-Id'), 0, 64) ?: null),
            'attempted_at' => $attemptedAt,
            'next_attempt_at' => $nextAttemptAt,
        ], [
            ...$deliveryChanges,
            'last_attempt_at' => $attemptedAt,
            'last_http_status' => $httpStatus,
            'last_error' => $errorMessage,
        ], $isSuccess);
    }

    /**
     * @return array{status: WebhookAttemptStatusEnum, errorCode: string|null, errorMessage: string|null, response: Response|null, secretPublicIds: list<string>|null}
     */
    private function send(WebhookDelivery $delivery, WebhookEndpoint $endpoint, int $webhookTimestamp): array
    {
        try {
            $signingSet = $this->secretResolver->signingSetFor($endpoint);
            $signedWebhook = $this->sign($delivery, $signingSet, $webhookTimestamp);
        } catch (WebhookException $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Refused, 'signing', $exception->getMessage());
        }

        try {
            $response = $this->client->send(
                'POST',
                $endpoint->url,
                function (OutboundRequest $request) use ($endpoint, $signedWebhook): void {
                    $request->withHeaders($this->staticHeadersOf($endpoint))
                        ->withHeaders($signedWebhook->headers)
                        ->withBody($signedWebhook->rawBody, 'application/json');
                },
                $this->requestOptionsFor($endpoint),
            );
        } catch (UnsafeUrl $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Refused, 'ssrf_refused', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Refused, 'request_refused', $exception->getMessage());
        } catch (OutboundHttpException $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Failed, 'transport', $exception->getMessage());
        }

        $secretPublicIds = $signingSet->secretPublicIds();

        if ($response->successful()) {
            return $this->outcome(WebhookAttemptStatusEnum::Delivered, null, null, $response, $secretPublicIds);
        }

        // Standard Webhooks "Delivery success and failure": 410 asks the sender to stop.
        $errorCode = $response->status() === 410 ? 'gone' : 'http_status';

        return $this->outcome(WebhookAttemptStatusEnum::Failed, $errorCode, 'HTTP '.$response->status(), $response, $secretPublicIds);
    }

    /**
     * @throws WebhookException when the body is gone or the signer refuses
     */
    private function sign(WebhookDelivery $delivery, WebhookSigningSet $signingSet, int $webhookTimestamp): SignedWebhook
    {
        if ($delivery->payload === null) {
            throw new WebhookException('Webhook delivery body has been pruned.');
        }

        return $this->signer->sign($delivery->event_id, $webhookTimestamp, $delivery->payload, $signingSet->secrets());
    }

    /**
     * @param  list<string>|null  $secretPublicIds
     * @return array{status: WebhookAttemptStatusEnum, errorCode: string|null, errorMessage: string|null, response: Response|null, secretPublicIds: list<string>|null}
     */
    private function outcome(
        WebhookAttemptStatusEnum $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?Response $response = null,
        ?array $secretPublicIds = null,
    ): array {
        return [
            'status' => $status,
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
            'response' => $response,
            'secretPublicIds' => $secretPublicIds,
        ];
    }

    /**
     * A retried delivery stays `pending`; only its next attempt moves.
     *
     * @return array{status?: DeliveryStatus, exhausted_reason?: WebhookExhaustedReasonEnum|null, next_attempt_at: CarbonImmutable|null}
     */
    private function scheduleAfterFailure(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $attemptNumber,
        ?int $httpStatus,
        ?int $retryAfterSeconds,
        CarbonImmutable $attemptedAt,
    ): array {
        $decision = $this->retryPolicy->decide($attemptNumber, $delivery->max_attempts, $this->retryDelaysFor($endpoint), $httpStatus, $retryAfterSeconds);

        if ($decision->delaySeconds === null) {
            return ['status' => DeliveryStatus::Exhausted, 'exhausted_reason' => $decision->exhaustedReason, 'next_attempt_at' => null];
        }

        return ['next_attempt_at' => $attemptedAt->addSeconds($decision->delaySeconds)];
    }

    /**
     * The endpoint's preset, or the configured one when it names none (or
     * one the package does not know).
     *
     * @return list<int>
     */
    private function retryDelaysFor(WebhookEndpoint $endpoint): array
    {
        return (WebhookRetryPresetEnum::tryFrom((string) $endpoint->retry_preset) ?? $this->defaultRetryPreset)->delays();
    }

    private function requestOptionsFor(WebhookEndpoint $endpoint): HttpRequestOptions
    {
        return new HttpRequestOptions(
            // An endpoint may raise its timeout, never past the configured maximum.
            timeoutSeconds: min($endpoint->timeout_seconds ?? $this->timeoutSeconds, $this->limits->maxTimeoutSeconds),
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            maxResponseBytes: $this->maxResponseBytes,
            // Endpoint switches can only add to the guard's own defaults.
            urlOptions: new UrlGuardOptions(
                httpsOnly: $endpoint->https_only ?? false,
                allowPrivateHosts: $endpoint->allow_private_hosts ?? false,
            ),
        );
    }

    /**
     * The endpoint's static headers, minus those the package sets: they
     * cannot replace the signature, its inputs or the content type.
     *
     * @return array<string, string>
     */
    private function staticHeadersOf(WebhookEndpoint $endpoint): array
    {
        return array_filter(
            $endpoint->headers ?? [],
            static fn (string $headerName): bool => ! in_array(
                strtolower($headerName),
                ['webhook-id', 'webhook-timestamp', 'webhook-signature', 'content-type'],
                true,
            ),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Response headers kept on an attempt (standards review R5): useful for
     * debugging, never a credential. Anything else, `Set-Cookie` included, is
     * dropped, and each value is cut at 255 characters.
     *
     * @return array<string, string>
     */
    private function keptResponseHeaders(Response $response): array
    {
        $keptHeaders = [];

        foreach (['content-type', 'retry-after', 'x-request-id', 'date'] as $headerName) {
            $headerValue = $response->header($headerName);

            if ($headerValue !== '') {
                $keptHeaders[$headerName] = mb_substr($headerValue, 0, 255);
            }
        }

        return $keptHeaders;
    }

    private function nanoseconds(): int
    {
        return $this->nanosecondClock === null ? hrtime(true) : ($this->nanosecondClock)();
    }
}
