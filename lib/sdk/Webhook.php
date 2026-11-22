<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk;

/** Vérification des rappels reçus sur votre serveur. */
final class Webhook
{
    /**
     * Vrai si le rappel vient bien de la plateforme.
     *
     * @param string      $rawBody   le corps BRUT, avant tout décodage JSON
     * @param null|string $signature l'en-tête `X-Fastermessage-Signature`
     * @param string      $secret    le secret généré dans « Intégration & API »
     */
    public static function isGenuine(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($signature === null || $secret === '') {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), trim($signature));
    }
}
