<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class Account extends Resource
{
    /**
     * Soldes du compte débité, par canal.
     *
     * @return list<array{channel: string, amount: int|float, currency: string}>
     */
    public function balance(): array
    {
        return ($this->http->request('GET', '/v2/balance') ?? [])['balances'] ?? [];
    }

    /**
     * @param array<string, mixed> $query `from`, `to`, `type`, `limit`, `cursor`
     *
     * @return array<string, mixed>
     */
    public function credits(array $query = []): array
    {
        return $this->http->request('GET', '/v2/credits', $query) ?? [];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function allCredits(array $query = []): \Generator
    {
        return $this->paginate('/v2/credits', $query);
    }
}
