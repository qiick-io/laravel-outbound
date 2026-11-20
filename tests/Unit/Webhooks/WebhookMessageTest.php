<?php

declare(strict_types=1);

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
