<?php

declare(strict_types=1);

namespace Qiick\Outbound\Erasure;

use LogicException;

/**
 * Outbound data has no organisation: endpoints are the organisation's
 * configuration in the product's own scope column, and the delivery log
 * follows them. An organisation-scoped run is refused before it writes.
 *
 * @api
 */
final class OrganisationScopeUnsupportedException extends LogicException {}
