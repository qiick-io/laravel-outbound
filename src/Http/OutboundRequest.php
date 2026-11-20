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
    /** @var list<string> */
    private const REFUSED_HEADERS = [
        'Host',
        'Forwarded',
        'X-Original-URL',
        'X-Rewrite-URL',
    ];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, scalar|null> */
    private array $query = [];

    private ?string $rawBody = null;

    private ?string $rawContentType = null;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /** @var array<string, mixed>|null */
    private ?array $form = null;

    /** @var array<string, mixed>|null */
    private ?array $multipart = null;

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader((string) $name, (string) $value);
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
            $this->query[(string) $key] = $value;
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
        $this->clearBody();
        $this->rawBody = $body;
        $this->rawContentType = $contentType;

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
        $this->clearBody();
        $this->json = $data;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function asForm(array $data): self
    {
        $this->clearBody();
        $this->form = $data;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function asMultipart(array $data): self
    {
        $this->clearBody();
        $this->multipart = $data;

        return $this;
    }

    /**
     * @return array{headers: array<string, string>, query: array<string, scalar|null>, body: array{type: string, payload: mixed, contentType: ?string}|null}
     */
    public function snapshot(): array
    {
        $body = null;

        if ($this->rawBody !== null) {
            $body = ['type' => 'raw', 'payload' => $this->rawBody, 'contentType' => $this->rawContentType];
        } elseif ($this->json !== null) {
            $body = ['type' => 'json', 'payload' => $this->json, 'contentType' => 'application/json'];
        } elseif ($this->form !== null) {
            $body = ['type' => 'form', 'payload' => $this->form, 'contentType' => 'application/x-www-form-urlencoded'];
        } elseif ($this->multipart !== null) {
            $body = ['type' => 'multipart', 'payload' => $this->multipart, 'contentType' => null];
        }

        return [
            'headers' => $this->headers,
            'query' => $this->query,
            'body' => $body,
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
            'multipart' => $request->asMultipart(self::stringKeyedArray($body['payload'], 'Multipart')),
            default => throw new InvalidArgumentException('Unknown outbound body type.'),
        };
    }

    private function assertHeaderAllowed(string $name): void
    {
        foreach (self::REFUSED_HEADERS as $refused) {
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

    private function clearBody(): void
    {
        $this->rawBody = null;
        $this->rawContentType = null;
        $this->json = null;
        $this->form = null;
        $this->multipart = null;
    }
}
