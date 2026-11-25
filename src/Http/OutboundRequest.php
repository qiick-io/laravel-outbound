<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use InvalidArgumentException;

/**
 * Narrow, package-owned request builder for {@see PinnedHttpClient::send()}.
 *
 * Callers may set headers, query values (merged onto the authorised URL only),
 * Accept / Content-Type, and a single body representation (raw, JSON, form, or
 * multipart). They never receive a Laravel PendingRequest, so they cannot change
 * the URI, timeouts, proxy, pin, redirects, verify, stream, or middleware.
 *
 * Host and hop/routing headers are refused — the Host header must stay the
 * authorised URL host while CONNECT_TO pins the checked address.
 *
 * @api
 */
final class OutboundRequest
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, scalar|null> */
    private array $query = [];

    /**
     * The one body this request sends, if any: setting a body replaces it.
     *
     * @var array{type: 'raw', payload: string, contentType: ?string}|array{type: 'json'|'form', payload: array<string, mixed>, contentType: ?string}|array{type: 'multipart', payload: list<array<string, mixed>>, contentType: null}|null
     */
    private ?array $body = null;

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            // A numeric name arrives as an integer array key.
            $this->withHeader((string) $name, $value);
        }

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->assertHeaderAllowed($name);
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function withQuery(array $query): self
    {
        foreach ($query as $key => $value) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    public function accept(string $value): self
    {
        return $this->withHeader('Accept', $value);
    }

    public function contentType(string $value): self
    {
        return $this->withHeader('Content-Type', $value);
    }

    public function withBody(string $body, ?string $contentType = null): self
    {
        $this->body = ['type' => 'raw', 'payload' => $body, 'contentType' => $contentType];

        if ($contentType !== null) {
            $this->withHeader('Content-Type', $contentType);
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function asJson(array $data): self
    {
        $this->body = ['type' => 'json', 'payload' => $data, 'contentType' => 'application/json'];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function asForm(array $data): self
    {
        $this->body = ['type' => 'form', 'payload' => $data, 'contentType' => 'application/x-www-form-urlencoded'];

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $data  the parts, as Guzzle's `multipart` option takes them
     */
    public function asMultipart(array $data): self
    {
        $this->body = ['type' => 'multipart', 'payload' => $data, 'contentType' => null];

        return $this;
    }

    /**
     * @return array{headers: array<string, string>, query: array<string, scalar|null>, body: array{type: 'raw', payload: string, contentType: ?string}|array{type: 'json'|'form', payload: array<string, mixed>, contentType: ?string}|array{type: 'multipart', payload: list<array<string, mixed>>, contentType: null}|null}
     */
    public function snapshot(): array
    {
        return [
            'headers' => $this->headers,
            'query' => $this->query,
            'body' => $this->body,
        ];
    }

    /**
     * @param  array{headers: array<string, string>, query: array<string, scalar|null>, body: array{type: string, payload: mixed, contentType: ?string}|null}  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $request = new self;
        $request->withHeaders($snapshot['headers']);
        $request->withQuery($snapshot['query']);

        $body = $snapshot['body'];

        if ($body === null) {
            return $request;
        }

        return match ($body['type']) {
            'raw' => $request->withBody(self::stringPayload($body['payload'], 'Raw'), $body['contentType']),
            'json' => $request->asJson(self::stringKeyedArray($body['payload'], 'JSON')),
            'form' => $request->asForm(self::stringKeyedArray($body['payload'], 'Form')),
            'multipart' => $request->asMultipart(self::multipartParts($body['payload'])),
            default => throw new InvalidArgumentException('Unknown outbound body type.'),
        };
    }

    private function assertHeaderAllowed(string $name): void
    {
        foreach (self::refusedHeaders() as $refused) {
            if (strcasecmp($name, $refused) === 0) {
                throw new InvalidArgumentException('Outbound request must not set Host or routing headers.');
            }
        }

        if (str_starts_with(strtolower($name), 'x-forwarded-')) {
            throw new InvalidArgumentException('Outbound request must not set Host or routing headers.');
        }
    }

    private static function stringPayload(mixed $payload, string $label): string
    {
        if (! is_string($payload)) {
            throw new InvalidArgumentException($label.' body must be a string.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $payload, string $label): array
    {
        if (! is_array($payload)) {
            throw new InvalidArgumentException($label.' body must be an array.');
        }

        $out = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException($label.' body keys must be strings.');
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function multipartParts(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new InvalidArgumentException('Multipart body must be a list of parts.');
        }

        return array_map(static fn (mixed $part): array => self::stringKeyedArray($part, 'Multipart part'), $payload);
    }

    /**
     * Headers that would route the request elsewhere: the Host header must
     * stay the authorised URL host while CONNECT_TO pins the checked address.
     * A method rather than a constant: a constant's lines have no coverage.
     *
     * @return list<string>
     */
    private static function refusedHeaders(): array
    {
        return ['Host', 'X-Host', 'X-Original-Host', 'Forwarded', 'X-Original-URL', 'X-Rewrite-URL'];
    }
}
