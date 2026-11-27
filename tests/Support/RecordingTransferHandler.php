<?php

declare(strict_types=1);

namespace Qiick\Outbound\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Http\Message\RequestInterface;
use Qiick\Outbound\Http\PinnedHttpClient;

/**
 * Innermost transfer handler for {@see PinnedHttpClient}
 * in delivery tests: it records each request with the transport options the
 * pinned client enforced, and answers from a queue (200 when the queue is
 * empty), so a test proves deliveries go through the pinned client.
 */
final class RecordingTransferHandler
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $transportOptions = [];

    /** @var list<Psr7Response|int> responses, or curl errnos for failures */
    private array $answers = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function respond(int $status, array $headers = [], string $body = ''): self
    {
        $this->answers[] = new Psr7Response($status, $headers, $body);

        return $this;
    }

    public function failToConnect(): self
    {
        return $this->failWithCurlError(7);
    }

    /**
     * Fail the next request the way curl does, with this errno in the message.
     */
    public function failWithCurlError(int $curlErrno): self
    {
        $this->answers[] = $curlErrno;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;
        $this->transportOptions[] = $options;
        $answer = array_shift($this->answers) ?? new Psr7Response(200);

        if (is_int($answer)) {
            return PromiseCreate::rejectionFor(new ConnectException("cURL error {$answer}: transfer failed", $request));
        }

        return PromiseCreate::promiseFor($answer);
    }
}
