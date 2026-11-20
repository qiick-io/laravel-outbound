<?php

declare(strict_types=1);

namespace Qiick\Outbound\Testing;

use Qiick\Outbound\Contracts\HostResolver;

/**
 * Deterministic HostResolver for tests. Map a hostname to answers, or supply
 * a queue of answer lists so redirect-hop DNS rebinding can be simulated.
 *
 * @api
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $answers
     * @param  array<string, list<list<string>>>  $queues
     */
    public function __construct(
        private array $answers = [],
        private array $queues = [],
    ) {}

    /**
     * @param  list<string>  $addresses
     */
    public function with(string $host, array $addresses): self
    {
        $clone = clone $this;
        $clone->answers[$host] = $addresses;

        return $clone;
    }

    /**
     * @param  list<list<string>>  $sequence
     */
    public function queue(string $host, array $sequence): self
    {
        $clone = clone $this;
        $clone->queues[$host] = $sequence;

        return $clone;
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (isset($this->queues[$host]) && $this->queues[$host] !== []) {
            return array_shift($this->queues[$host]);
        }

        return $this->answers[$host] ?? [];
    }
}
