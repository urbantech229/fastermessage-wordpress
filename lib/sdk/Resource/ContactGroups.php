<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class ContactGroups extends Resource
{
    /**
     * @param array<string, mixed> $params `name`, et en option `fields` (5 libellés)
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/v2/contact-groups', [], $params) ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/v2/contact-groups/' . self::segment($id)) ?? [];
    }

    /**
     * @param array<string, mixed> $query `limit`, `cursor`
     *
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/v2/contact-groups', $query) ?? [];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        return $this->paginate('/v2/contact-groups', $query);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->http->request('PATCH', '/v2/contact-groups/' . self::segment($id), [], $params) ?? [];
    }

    /** Le groupe doit être vide. */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v2/contact-groups/' . self::segment($id));
    }
}
