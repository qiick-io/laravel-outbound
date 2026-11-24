<?php

declare(strict_types=1);

use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookMessage;

it('encodes a compact envelope without embedding the webhook id', function (): void {
    $message = new WebhookMessage(
        id: 'msg_1',
        type: 'contact.created',
        timestamp: new DateTimeImmutable('2022-11-03T20:26:10+00:00'),
        data: ['id' => '1f81eb52-5198-4599-803e-771906343485'],
    );

    $json = $message->toPayloadJson();

    expect($json)->not->toContain('msg_1')
        ->and($json)->toContain('"type":"contact.created"')
        ->and($json)->not->toContain("\n")
        ->and(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
            'type' => 'contact.created',
            'data' => ['id' => '1f81eb52-5198-4599-803e-771906343485'],
        ]);
});

it('encodes empty data as a JSON object not an array', function (): void {
    $message = new WebhookMessage(
        id: 'msg_empty',
        type: 'ping',
        timestamp: new DateTimeImmutable('2022-11-03T20:26:10+00:00'),
        data: [],
    );

    expect($message->toPayloadJson())->toContain('"data":{}')
        ->and($message->toPayloadJson())->not->toContain('"data":[]');
});

it('encodes the timestamp and keeps slashes and non-ASCII text unescaped', function (): void {
    $message = new WebhookMessage(
        id: 'msg_exact',
        type: 'contact.created',
        timestamp: new DateTimeImmutable('2022-11-03T20:26:10+00:00'),
        data: ['url' => 'https://example.com/a', 'name' => 'Zoë'],
    );

    expect($message->toPayloadJson())
        ->toBe('{"type":"contact.created","timestamp":"2022-11-03T20:26:10+00:00","data":{"url":"https://example.com/a","name":"Zoë"}}');
});

it('refuses data that cannot be encoded as JSON', function (): void {
    $message = new WebhookMessage(
        id: 'msg_invalid_utf8',
        type: 'contact.created',
        timestamp: new DateTimeImmutable('2022-11-03T20:26:10+00:00'),
        data: ['name' => "\xB1\x31"],
    );

    try {
        $message->toPayloadJson();
        $exception = null;
    } catch (WebhookException $caughtException) {
        $exception = $caughtException;
    }

    expect($exception?->getMessage())->toBe('Webhook payload could not be encoded as JSON.')
        ->and($exception?->getCode())->toBe(0)
        ->and($exception?->getPrevious())->toBeInstanceOf(JsonException::class);
});
