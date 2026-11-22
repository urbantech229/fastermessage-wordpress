<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class Messages extends Resource
{
    /**
     * Envoi avec le canal en paramètre : `channel` vaut `sms`, `email` ou `whatsapp`.
     *
     * `fallback` est un tableau ordonné d'étapes `['channel' => 'sms'|'email'|'whatsapp', 'to', 'from', 'content']` :
     * `content` est obligatoire vers le SMS (chaîne ou `['text', 'unicode']`), facultatif vers l'e-mail,
     * et vers WhatsApp un modèle (`template`, `language`, `variables`) ou un texte (`text`, dans la fenêtre de 24 h).
     * La réponse porte alors `fallback` (`will_run`, `state`, `steps`), `null` sans repli.
     * `test_outcome` (`failed` ou `delivered`) simule l'issue, avec une clé `fm_test_` seulement.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed>
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/messages', [], $params, $options) ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/v2/messages/' . self::segment($id)) ?? [];
    }

    /**
     * Une page. Attention : sans `channel`, l'API ne rend que les SMS.
     *
     * @param array<string, mixed> $query `channel`, `status`, `client_reference`, `limit`, `cursor`
     *
     * @return array<string, mixed> `data`, `has_more`, `next_cursor`
     */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/v2/messages', $query) ?? [];
    }

    /**
     * Tous les messages, page après page.
     *
     * @param array<string, mixed> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $query = []): \Generator
    {
        return $this->paginate('/v2/messages', $query);
    }
}
