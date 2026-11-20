<?php

declare(strict_types=1);

namespace Qiick\Outbound\Tests\Fixtures\StandardWebhooks;

/**
 * Official Standard Webhooks test vectors.
 *
 * These values are public fixtures from the Standard Webhooks repository — they
 * are never real secrets and must not be treated as credentials. Any other test
 * secret in this package is built at runtime (e.g. WebhookSecret::generate()).
 *
 * Source files (standard-webhooks/standard-webhooks @ 3d65b1824b6697f05a5a4584e7062bd23512fc8e):
 * - libraries/php/tests/WebhookTest.php (testSignFunctionWorks, line ~181)
 * - libraries/php/tests/TestPayload.php (DEFAULT_MSG_ID / DEFAULT_SECRET / DEFAULT_PAYLOAD)
 *
 * @see https://github.com/standard-webhooks/standard-webhooks/blob/3d65b1824b6697f05a5a4584e7062bd23512fc8e/libraries/php/tests/WebhookTest.php
 * @see https://github.com/standard-webhooks/standard-webhooks/blob/3d65b1824b6697f05a5a4584e7062bd23512fc8e/libraries/php/tests/TestPayload.php
 */
final class OfficialVectors
{
    /** @see WebhookTest::testSignFunctionWorks */
    public const string SIGN_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    /** @see WebhookTest::testSignFunctionWorks / TestPayload::DEFAULT_MSG_ID */
    public const string SIGN_MSG_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    /** @see WebhookTest::testSignFunctionWorks */
    public const int SIGN_TIMESTAMP = 1614265330;

    /** @see WebhookTest::testSignFunctionWorks */
    public const string SIGN_PAYLOAD = '{"test": 2432232314}';

    /** @see WebhookTest::testSignFunctionWorks */
    public const string SIGN_EXPECTED = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    /** @see TestPayload::DEFAULT_SECRET (base64 without whsec_ prefix) */
    public const string TEST_PAYLOAD_SECRET_B64 = 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    /** @see TestPayload::DEFAULT_PAYLOAD */
    public const string TEST_PAYLOAD_BODY = '{"test": 2432232315}';

    /** @see TestPayload::DEFAULT_MSG_ID */
    public const string TEST_PAYLOAD_MSG_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
}
