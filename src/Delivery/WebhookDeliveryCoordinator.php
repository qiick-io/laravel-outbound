<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Qiick\Outbound\Contracts\LegacyWebhookProfileContract;
use Qiick\Outbound\Enums\WebhookAttemptStatusEnum;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Events\WebhookDeliveryFailedEvent;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Events\WebhookRedeliveryRequestedEvent;
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
use Throwable;

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
     * @param  array<string, LegacyWebhookProfileContract>  $legacyProfiles  `outbound.webhooks.legacy_profiles`, by name
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
        private readonly ExceptionHandler $exceptionHandler,
        private readonly EndpointDisablePolicy $disablePolicy,
        private readonly EventDispatcher $events,
        private readonly ?Closure $nanosecondClock = null,
        private readonly array $legacyProfiles = [],
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
            $retryDelays = $this->retryDelaysFor($endpoint);
            $maxAttempts = $endpoint->max_attempts ?? count($retryDelays) + 1;
            // An unknown profile keeps the plain body; every attempt is refused.
            $body = $this->legacyProfileOf($endpoint)?->body($message, $rawBody) ?? $rawBody;
            $delivery = $this->store->createDelivery($endpoint, $message, $body, $maxAttempts, $this->retryPolicy->longestPlanSeconds($retryDelays, $maxAttempts));

            if ($delivery !== null) {
                $createdDeliveries->push($delivery);
            }
        }

        return $createdDeliveries;
    }

    /**
     * Send one attempt for each of up to `$limit` due deliveries, and, with
     * `$maxSeconds`, start none once that long has passed since the run
     * began: an attempt already on the wire finishes, so a run can overrun
     * the budget by one send (at most the chain deadline).
     *
     * Each delivery is leased on its own, right before its send, for the
     * longest a send can take (the redirect-chain deadline) plus a minute: a
     * run that overlaps this one can never take a delivery this one is still
     * going to send. One delivery's failure is recorded or reported and the
     * batch carries on.
     *
     * Side effects: outbound HTTP POSTs; attempt rows; delivery and endpoint
     * updates; {@see WebhookDeliveryFailedEvent} for a delivery an attempt
     * left exhausted; unexpected errors reported to the exception handler.
     *
     * @return int the attempts sent or refused
     *
     * @throws InvalidArgumentException when `$limit` or `$maxSeconds` is below 1
     */
    public function work(int $limit, ?int $maxSeconds = null): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The work limit must be at least 1.');
        }

        if ($maxSeconds !== null && $maxSeconds < 1) {
            throw new InvalidArgumentException('The work time budget must be at least 1 second.');
        }

        $attemptCount = 0;
        $deadline = $maxSeconds === null ? null : $this->nanoseconds() + $maxSeconds * 1_000_000_000;

        foreach ($this->store->dueCandidates($limit, $this->defaultMaxConcurrency) as $deliveryId) {
            if ($deadline !== null && $this->nanoseconds() >= $deadline) {
                return $attemptCount;
            }

            $delivery = $this->store->claim($deliveryId, $this->limits->totalDeadlineSeconds + 60, $this->defaultMaxConcurrency);

            if ($delivery === null) {
                continue;
            }

            $attemptCount++;

            try {
                $this->attempt($delivery);
            } catch (Throwable $exception) {
                // Even the minimal record failed (the database is gone): the
                // lease expires and the delivery is sent again later.
                $this->exceptionHandler->report($exception);
            }
        }

        return $attemptCount;
    }

    private function attempt(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        $attemptNumber = $delivery->attempts_count + 1;
        $attemptedAt = CarbonImmutable::now('UTC');
        $startedAt = $this->nanoseconds();

        try {
            $outcome = $this->send($delivery, $endpoint, $attemptedAt->getTimestamp());
        } catch (Throwable) {
            // An endpoint row the request cannot be built from (a non-string
            // header, a value encrypted under a lost APP_KEY): the attempt
            // still counts, so the delivery moves towards exhausted.
            $outcome = $this->outcome(WebhookAttemptStatusEnum::Refused, 'internal_error', 'The attempt could not be prepared.');
        }

        $durationMilliseconds = intdiv($this->nanoseconds() - $startedAt, 1_000_000);

        $response = $outcome['response'];
        $httpStatus = $response?->status();
        // A missing header reads as '', which parses to null.
        $retryAfterSeconds = $response === null ? null : RetryAfter::parse($this->firstHeaderValue($response, 'Retry-After'), $attemptedAt);
        $isSuccess = $outcome['status'] === WebhookAttemptStatusEnum::Delivered;
        // A refusal (signing, guard, internal error) is the package's, not the receiver's.
        $isReceiverOutcome = $outcome['status'] !== WebhookAttemptStatusEnum::Refused;
        $deliveryChanges = $isSuccess
            ? ['status' => DeliveryStatus::Delivered, 'delivered_at' => $attemptedAt, 'next_attempt_at' => null]
            : $this->scheduleAfterFailure($delivery, $endpoint, $attemptNumber, $httpStatus, $retryAfterSeconds, $attemptedAt);
        $nextAttemptAt = $deliveryChanges['next_attempt_at'];

        $errorMessage = DeliveryAttempt::sanitiseErrorMessage($outcome['errorMessage']);

        $attemptAttributes = [
            // A redelivery request's trigger and user; otherwise `schedule`.
            'trigger' => $delivery->next_attempt_trigger ?? WebhookAttemptTriggerEnum::Schedule,
            'triggered_by' => $delivery->next_attempt_triggered_by,
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
            'request_id' => $response === null ? null : $this->requestIdOf($response),
            'attempted_at' => $attemptedAt,
            'next_attempt_at' => $nextAttemptAt,
        ];
        $deliveryChanges = [
            ...$deliveryChanges,
            'last_attempt_at' => $attemptedAt,
            'last_http_status' => $httpStatus,
            'last_error' => $errorMessage,
            'next_attempt_trigger' => null,
            'next_attempt_triggered_by' => null,
        ];

        try {
            $isRecorded = $this->store->recordAttempt($delivery, $attemptAttributes, $deliveryChanges, $isSuccess, $isReceiverOutcome);
        } catch (Throwable) {
            // The response text is the receiver's: should the database refuse
            // it, the outcome still counts, so a delivered webhook is never
            // sent again and a failing one still moves towards exhausted.
            $isRecorded = $this->store->recordAttempt($delivery, [...$attemptAttributes, 'response_headers' => null, 'request_id' => null], $deliveryChanges, $isSuccess, $isReceiverOutcome);
        }

        // A redelivery asked during the send keeps the delivery pending, so
        // the stored status decides.
        if ($isRecorded && $delivery->refresh()->status === DeliveryStatus::Exhausted) {
            $this->events->dispatch(new WebhookDeliveryFailedEvent(
                $delivery->public_id,
                $endpoint->public_id,
                $delivery->event_type,
                // An exhausted delivery always has its reason; the fallback only types it.
                $delivery->exhausted_reason ?? WebhookExhaustedReasonEnum::MaxAttempts,
                $delivery->attempts_count,
                $attemptedAt,
            ));
        }

        // An attempt another worker already recorded, or a refusal on the
        // package's side, decides nothing.
        if ($isRecorded && $isReceiverOutcome && ! $isSuccess) {
            $disableReason = $this->disablePolicy->reasonToDisable($endpoint->refresh(), $httpStatus, $attemptedAt);

            if ($disableReason !== null) {
                $this->disable($endpoint, $disableReason, $attemptedAt, $this->disablePolicy->streakStillRequired($disableReason, $attemptedAt));
            }
        }
    }

    /**
     * Send one delivery again, with the same `webhook-id` (its event id), a
     * fresh `webhook-timestamp` and fresh signatures: Standard Webhooks
     * "Webhook metadata" keeps the id across retries, so a consumer that
     * already processed it deduplicates it (standards review R7). The worker
     * sends it on its next run. A settled delivery gets exactly one more
     * attempt; a pending one keeps its schedule.
     *
     * Side effect: fires {@see WebhookRedeliveryRequestedEvent}.
     *
     * @param  int|null  $triggeredBy  the user who asked, recorded on the attempt
     * @return bool false when the delivery no longer exists
     *
     * @throws WebhookException when the endpoint is disabled or deleted, or
     *                          the body has been pruned
     */
    public function redeliver(WebhookDelivery $delivery, ?int $triggeredBy = null): bool
    {
        $this->assertCanRedeliverTo($delivery->endpoint);

        if ($delivery->status === DeliveryStatus::Cancelled) {
            throw new WebhookException('A cancelled delivery was withdrawn and cannot be redelivered.');
        }

        if ($delivery->payload === null || $delivery->payload_expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new WebhookException('Webhook delivery body has been pruned or has expired; it cannot be redelivered.');
        }

        // Checked again in the update itself: a prune or a cancel may have
        // run since the caller loaded the delivery.
        $scheduledCount = $this->store->scheduleRedelivery(
            WebhookDelivery::query()
                ->whereKey($delivery->id)
                ->tap($this->withLiveBody(...))
                ->where('status', '!=', DeliveryStatus::Cancelled),
            WebhookAttemptTriggerEnum::Manual,
            $triggeredBy,
        );

        if ($scheduledCount === 0) {
            return false;
        }

        $this->events->dispatch(new WebhookRedeliveryRequestedEvent(
            $delivery->endpoint->public_id,
            $scheduledCount,
            WebhookAttemptTriggerEnum::Manual,
            $triggeredBy,
            deliveryPublicId: $delivery->public_id,
        ));

        return true;
    }

    /**
     * Send again every delivery of the endpoint created in [`$from`,
     * `$until`) whose status is one of `$statuses` ("Redeliver failed
     * since…"), as {@see redeliver()} does one. Deliveries whose body has been
     * pruned are skipped. The worker's fairness keeps a large range from
     * starving other endpoints.
     *
     * Side effect: fires {@see WebhookRedeliveryRequestedEvent} when any
     * delivery was scheduled.
     *
     * @param  list<DeliveryStatus>  $statuses
     * @return int the deliveries scheduled
     *
     * @throws WebhookException when the endpoint is disabled or deleted, the
     *                          range is empty, or no status is given
     */
    public function redeliverRange(WebhookEndpoint $endpoint, CarbonImmutable $from, CarbonImmutable $until, array $statuses, ?int $triggeredBy = null): int
    {
        $this->assertCanRedeliverTo($endpoint);

        if ($until <= $from) {
            throw new WebhookException('The redelivery range must end after it starts.');
        }

        if ($statuses === []) {
            throw new WebhookException('A bulk redelivery needs at least one status.');
        }

        if (in_array(DeliveryStatus::Cancelled, $statuses, true)) {
            throw new WebhookException('A cancelled delivery was withdrawn and cannot be redelivered.');
        }

        $scheduledCount = $this->store->scheduleRedelivery(
            WebhookDelivery::query()
                ->where('endpoint_id', $endpoint->id)
                ->where('created_at', '>=', $from->utc())
                ->where('created_at', '<', $until->utc())
                // Laravel binds backed enums by their value.
                ->whereIn('status', $statuses)
                ->tap($this->withLiveBody(...)),
            WebhookAttemptTriggerEnum::Bulk,
            $triggeredBy,
        );

        if ($scheduledCount > 0) {
            $this->events->dispatch(new WebhookRedeliveryRequestedEvent(
                $endpoint->public_id,
                $scheduledCount,
                WebhookAttemptTriggerEnum::Bulk,
                $triggeredBy,
                from: $from,
                until: $until,
                statuses: $statuses,
            ));
        }

        return $scheduledCount;
    }

    /**
     * Deliveries whose body is still there and not yet expired: a body past
     * its retention may only wait for the next prune.
     *
     * @param  Builder<WebhookDelivery>  $deliveriesQuery
     */
    private function withLiveBody(Builder $deliveriesQuery): void
    {
        $deliveriesQuery->whereNotNull('payload')->where('payload_expires_at', '>', CarbonImmutable::now('UTC'));
    }

    /**
     * @throws WebhookException when the endpoint is disabled or deleted: its
     *                          deliveries would wait unseen
     */
    private function assertCanRedeliverTo(WebhookEndpoint $endpoint): void
    {
        if (! $endpoint->is_active || $endpoint->trashed()) {
            throw new WebhookException('Webhook endpoint is disabled; enable it before redelivering.');
        }
    }

    /**
     * Stop deliveries to an endpoint by hand (`disabled_reason = manual`).
     * Its pending deliveries wait; new events are not stored for it.
     *
     * Side effect: fires {@see WebhookEndpointDisabledEvent} once.
     *
     * @return bool false when the endpoint was already disabled
     */
    public function disableEndpoint(WebhookEndpoint $endpoint): bool
    {
        return $this->disable($endpoint, WebhookEndpointDisabledReasonEnum::Manual, CarbonImmutable::now('UTC'));
    }

    /**
     * Resume deliveries to a disabled endpoint, with a clean failure streak,
     * so the next failure does not disable it again at once. Its pending
     * deliveries resume on their schedule.
     *
     * @return bool false when the endpoint was already active
     */
    public function enableEndpoint(WebhookEndpoint $endpoint): bool
    {
        return $this->store->enableEndpoint($endpoint->id);
    }

    /**
     * @param  array{minConsecutiveFailures: int, failingSinceAtLatest: CarbonImmutable|null}|null  $streakStillRequired
     */
    private function disable(WebhookEndpoint $endpoint, WebhookEndpointDisabledReasonEnum $reason, CarbonImmutable $disabledAt, ?array $streakStillRequired = null): bool
    {
        if (! $this->store->disableEndpoint($endpoint->id, $reason, $disabledAt, $streakStillRequired)) {
            return false;
        }

        $this->events->dispatch(new WebhookEndpointDisabledEvent($endpoint->public_id, $reason, $disabledAt));

        return true;
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

        // Recorded from here on: after a transport error the receiver may have the body.
        $secretPublicIds = $signingSet->secretPublicIds();

        try {
            $legacyHeaders = $this->legacyHeadersFor($endpoint, $signedWebhook, $signingSet);
        } catch (WebhookException $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Refused, 'legacy_profile', $exception->getMessage(), null, $secretPublicIds);
        }

        try {
            $response = $this->client->send(
                'POST',
                $endpoint->url,
                function (OutboundRequest $request) use ($endpoint, $signedWebhook, $legacyHeaders): void {
                    $request->withHeaders($this->staticHeadersOf($endpoint))
                        ->withHeaders($legacyHeaders)
                        ->withHeaders($signedWebhook->headers)
                        ->withBody($signedWebhook->rawBody, 'application/json');
                },
                $this->requestOptionsFor($endpoint),
            );
        } catch (UnsafeUrl $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Refused, 'ssrf_refused', $exception->getMessage(), null, $secretPublicIds);
        } catch (InvalidArgumentException $exception) {
            return $this->outcome(WebhookAttemptStatusEnum::Refused, 'request_refused', $exception->getMessage(), null, $secretPublicIds);
        } catch (OutboundHttpException $exception) {
            // timeout, tls, dns or connection; "transport" for a refusal of the
            // client's own without a kind (a response over max_response_bytes).
            $errorCode = $exception->transportErrorKind()->value ?? 'transport';

            return $this->outcome(WebhookAttemptStatusEnum::Failed, $errorCode, $exception->getMessage(), null, $secretPublicIds);
        }

        if ($response->successful()) {
            return $this->outcome(WebhookAttemptStatusEnum::Delivered, null, null, $response, $secretPublicIds);
        }

        // Standard Webhooks "Delivery success and failure": 410 asks the sender to stop.
        $errorCode = $response->status() === 410 ? 'gone' : 'http_status';

        return $this->outcome(WebhookAttemptStatusEnum::Failed, $errorCode, 'HTTP '.$response->status(), $response, $secretPublicIds);
    }

    /**
     * The headers of the endpoint's legacy profile, none without one.
     *
     * @return array<string, string>
     *
     * @throws WebhookException when the profile is not registered, or names a
     *                          Standard Webhooks header
     */
    private function legacyHeadersFor(WebhookEndpoint $endpoint, SignedWebhook $signedWebhook, WebhookSigningSet $signingSet): array
    {
        if ($endpoint->legacy_profile === null) {
            return [];
        }

        $profile = $this->legacyProfileOf($endpoint) ?? throw new WebhookException('The endpoint names a legacy profile that is not registered.');
        $headers = $profile->headers($signedWebhook, $signingSet->secrets());

        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower($name), ['webhook-id', 'webhook-timestamp', 'webhook-signature'], true)) {
                throw new WebhookException('A legacy profile must not set a Standard Webhooks header.');
            }
        }

        return $headers;
    }

    /**
     * The registered profile the endpoint's `legacy_profile` names; null
     * without one, or when the name is not registered.
     */
    private function legacyProfileOf(WebhookEndpoint $endpoint): ?LegacyWebhookProfileContract
    {
        return $endpoint->legacy_profile === null ? null : $this->legacyProfiles[$endpoint->legacy_profile] ?? null;
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
            urlOptions: new UrlGuardOptions(
                // Only tightens: the guard ORs it with its own setting.
                httpsOnly: $endpoint->https_only ?? false,
                // Only narrows: ssrf.allow_private_hosts stays the ceiling.
                allowPrivateHosts: $endpoint->allow_private_hosts,
            ),
        );
    }

    /**
     * The endpoint's static headers, minus those the package sets: they
     * cannot replace the signature, its inputs or the content type. A value
     * that is not a string makes the request unbuildable (an internal error).
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
            // Valid UTF-8 without control or format characters, cut by
            // characters: the database refuses anything else, and products
            // show these values.
            // @phpstan-ignore argument.type (a fixed pattern on scrubbed UTF-8: preg_replace cannot fail)
            $headerValue = mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', '', mb_scrub($this->firstHeaderValue($response, $headerName), 'UTF-8')), 0, 255);

            if ($headerValue !== '') {
                $keptHeaders[$headerName] = $headerValue;
            }
        }

        return $keptHeaders;
    }

    /**
     * The receiver's `X-Request-Id`, reduced to a token (letters, digits and
     * `._:-`, at most 64): it ends up in product views and in a `string(64)`.
     */
    private function requestIdOf(Response $response): ?string
    {
        // @phpstan-ignore argument.type (a fixed byte pattern: preg_replace cannot fail)
        return substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $this->firstHeaderValue($response, 'X-Request-Id')), 0, 64) ?: null;
    }

    /**
     * The first value of a header: a repeated header is joined with commas
     * otherwise, which no parser here accepts.
     */
    private function firstHeaderValue(Response $response, string $headerName): string
    {
        return $response->toPsrResponse()->getHeader($headerName)[0] ?? '';
    }

    private function nanoseconds(): int
    {
        return $this->nanosecondClock === null ? hrtime(true) : ($this->nanosecondClock)();
    }
}
