<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class Whatsapp extends Resource
{
    /**
     * Envoie un message WhatsApp. Champs : `to`, `from` (numéro WhatsApp raccordé au compte,
     * obligatoire) et `content`, l'un ou l'autre :
     *
     * - un modèle approuvé, à tout moment : `['template' => 'nom', 'language' => 'fr', 'variables' => [...]]`.
     *   Variables : `'1'`, `'2'`… pour `{{1}}`, `{{2}}`… du corps, `'header'` pour l'en-tête texte,
     *   `'button_1'`… pour le lien d'un bouton. `language` est facultatif si le modèle n'existe qu'en une langue ;
     * - un texte libre, seulement si le destinataire a écrit à ce numéro dans les 24 h :
     *   `['text' => '...']` (sinon `409 session_window_closed`).
     *
     * En option : `fallback` (étapes vers le SMS ou l'e-mail ; après un modèle, une étape e-mail porte
     * son contenu), `test_outcome`, `callback_url`, `client_reference`. L'envoi programmé n'est pas
     * encore ouvert sur WhatsApp. Un message lu reste `delivered` ; sa lecture se lit dans `read_at`.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> la ressource message
     */
    public function send(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/whatsapp/send', [], $params, $options) ?? [];
    }

    /**
     * Une page des numéros WhatsApp qui peuvent envoyer maintenant : ceux que `from` accepte.
     * Chaque numéro : `number` (à mettre dans `from`), `display_name`, `label`, `quality`, `is_default`.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed> `data`, `has_more`, `next_cursor`
     */
    public function listNumbers(array $query = []): array
    {
        return $this->http->request('GET', '/v2/whatsapp/numbers', $query) ?? [];
    }

    /**
     * Tous les numéros WhatsApp envoyables, page après page.
     *
     * @param array{limit?: int} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function allNumbers(array $query = []): \Generator
    {
        return $this->paginate('/v2/whatsapp/numbers', $query);
    }

    /**
     * Une page des modèles approuvés et envoyables par l'API. Chaque modèle : `id`, `name`,
     * `language`, `category`, `header_text`, `body`, `footer`, `variables` (les clés attendues
     * dans `content.variables` : `'1'`, `'2'`…, `'header'`, `'button_1'`…) et `number`, le numéro
     * de son compte WhatsApp Business (Meta refuse un modèle envoyé depuis un autre compte).
     * Un modèle de catégorie `authentication` sert `$fm->otp->request()`, pas `send()`.
     *
     * @param array{limit?: int, cursor?: string} $query
     *
     * @return array<string, mixed> `data`, `has_more`, `next_cursor`
     */
    public function listTemplates(array $query = []): array
    {
        return $this->http->request('GET', '/v2/whatsapp/templates', $query) ?? [];
    }

    /**
     * Tous les modèles envoyables, page après page.
     *
     * @param array{limit?: int} $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function allTemplates(array $query = []): \Generator
    {
        return $this->paginate('/v2/whatsapp/templates', $query);
    }
}
