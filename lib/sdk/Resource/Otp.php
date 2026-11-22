<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Resource;

final class Otp extends Resource
{
    /**
     * Émet un code. Sans `channel`, le SMS est retenu ; `channel` => `whatsapp` envoie le code par votre
     * modèle d'authentification approuvé : `from` est alors le numéro WhatsApp (obligatoire), `template`
     * le nom du modèle et `language` sa langue, tous deux facultatifs s'il est unique.
     *
     * Le repli (`fallback`, vers le SMS, l'e-mail ou WhatsApp) reprend la forme des envois ; un `content`
     * propre (SMS, e-mail) doit contenir `{code}`. `callback_url` reçoit le rappel de repli, à défaut l'adresse du compte.
     * `test_outcome` (`failed` ou `delivered`) simule l'issue, avec une clé `fm_test_` seulement.
     *
     * @param array<string, mixed> $params `to`, et en option `channel`, `from`, `length`, `ttl_seconds`, `template`, `language`,
     *                                     `client_reference`, `fallback`, `test_outcome`, `callback_url`
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return array<string, mixed> conservez `id` ; le code n'est jamais renvoyé. `fallback` vaut `null` sans repli
     */
    public function request(array $params, array $options = []): array
    {
        return $this->http->request('POST', '/v2/otp', [], $params + ['channel' => 'sms'], $options) ?? [];
    }

    /**
     * Vérifie un code. Un code faux ne lève rien : décidez sur `status`, qui vaut
     * `approved`, `rejected` ou `expired`.
     *
     * @return array<string, mixed>
     */
    public function verify(string $id, string $code): array
    {
        return $this->http->request('POST', '/v2/otp/verify', [], ['id' => $id, 'code' => $code]) ?? [];
    }
}
