<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class Email extends Resource
{
    /**
     * Envoie un e-mail à une adresse. Champs : `to`, `content` (`subject`, et `html`
     * et/ou `text`), et en option `from`, `fallback`, `test_outcome`, `scheduled_at`, `callback_url`,
     * `client_reference`.
     *
     * `fallback` est un tableau ordonné d'étapes `['channel' => 'sms'|'whatsapp', 'to', 'from', 'content']` :
     * `content` est obligatoire vers le SMS (chaîne ou `['text', 'unicode']`), et vers WhatsApp un modèle
     * (`template`, `language`, `variables`) ou un texte (`text`, dans la fenêtre de 24 h).
     * La réponse porte alors `fallback` (`will_run`, `state`, `steps`), `null` sans repli.
     * `test_outcome` (`failed` ou `delivered`) simule l'issue, avec une clé `fm_test_` seulement.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> la ressource message
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/email/send', [], $params, $options) ?? [];
    }

    /**
     * Une page des adresses expéditrices utilisables dans `from` : adresses actives (domaine
     * authentifié), et l'adresse par défaut de votre marque si vous y avez droit (`brand_default`).
     * Chaque adresse : `address`, `name`, `domain`, `brand_default`.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed> `data`, `has_more`, `next_cursor`
     */
    public function listSenders(array $query = []): array
    {
        return $this->http->request('GET', '/v2/email/senders', $query) ?? [];
    }

    /**
     * Toutes les adresses expéditrices utilisables, page après page.
     *
     * @param array{limit?: int} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function allSenders(array $query = []): \Generator
    {
        return $this->paginate('/v2/email/senders', $query);
    }

    /**
     * Une page des modèles e-mail publiés. Chaque modèle : `id`, `name`, `subject`, `html`,
     * `from_email` (`null` si l'adresse du modèle n'est plus utilisable), `from_name`, `reply_to`.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed> `data`, `has_more`, `next_cursor`
     */
    public function listTemplates(array $query = []): array
    {
        return $this->http->request('GET', '/v2/email/templates', $query) ?? [];
    }

    /**
     * Tous les modèles e-mail publiés, page après page.
     *
     * @param array{limit?: int} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function allTemplates(array $query = []): \Generator
    {
        return $this->paginate('/v2/email/templates', $query);
    }
}
