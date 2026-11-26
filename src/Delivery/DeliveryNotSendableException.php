<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use RuntimeException;

/**
 * Thrown from the before-transport check when a claimed delivery was
 * cancelled, or its body expired, since the claim: the coordinator sends and
 * records nothing.
 *
 * @internal
 */
final class DeliveryNotSendableException extends RuntimeException {}
