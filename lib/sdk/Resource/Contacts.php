<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class Contacts extends Resource
{
    /**
     * @param array<string, mixed> $params `group_id`, `phone_number`, et en option `email`, `whatsapp`, `consent`, `fields`
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/v2/contacts', [], $params) ?? [];
    }

    /**
     * Crée jusqu'à 500 contacts dans un groupe.
     *
     * @param array<string, mixed> $params `group_id`, `contacts`, et en option `on_duplicate`
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> le bilan
     */
    public function createBatch(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/contacts/batch', [], $params, $options) ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/v2/contacts/' . self::segment($id)) ?? [];
    }

    /**
     * @param array<string, mixed> $query `group_id`, `consent`, `search`, `limit`, `cursor`
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/v2/contacts', $query) ?? [];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        return $this->paginate('/v2/contacts', $query);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', '/v2/contacts/' . self::segment($id), [], $params) ?? [];
    }

    /** Suppression définitive. Ce n'est pas un désabonnement : pour cela, `consent: out`. */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v2/contacts/' . self::segment($id));
    }
}
