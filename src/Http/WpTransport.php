<?php

declare(strict_types=1);

namespace FasterMessageWP\Http;

use FasterMessageWP\Sdk\Exception\ConnectionException;
use FasterMessageWP\Sdk\Http\Response;
use FasterMessageWP\Sdk\Http\Transport;

/**
 * TRANSPORT du SDK sur l'API HTTP de WordPress (`wp_remote_request`).
 *
 * Plutôt que l'extension cURL du SDK : l'API de WordPress respecte la
 * configuration du site — mandataire sortant (`WP_PROXY_*`), magasin de
 * certificats de WordPress, filtres `http_request_args` posés par l'hébergeur —
 * et fonctionne là où cURL n'est pas compilé.
 *
 * Les garde-fous du SDK sont conservés :
 *  · AUCUNE REDIRECTION suivie (`redirection => 0`) : une redirection perdrait le
 *    verbe, ou enverrait les identifiants à un autre hôte ;
 *  · certificat TLS VÉRIFIÉ (`sslverify => true`), y compris si un filtre global
 *    du site tentait de le désactiver pour toutes les requêtes.
 */
final class WpTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $userAgent = 'fastermessage-wordpress/' . (defined('FASTERMESSAGE_VERSION') ? FASTERMESSAGE_VERSION : 'dev');
        unset($headers['User-Agent']);

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'redirection' => 0,
            'user-agent' => $userAgent,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        // Posé APRÈS les filtres du site : la vérification du certificat n'est pas
        // négociable quand la requête porte des identifiants.
        $enforce = static function (array $parsed, string $target) use ($url): array {
            if ($target === $url) {
                $parsed['sslverify'] = true;
                $parsed['redirection'] = 0;
            }

            return $parsed;
        };
        add_filter('http_request_args', $enforce, PHP_INT_MAX, 2);
        try {
            $result = wp_remote_request($url, $args);
        } finally {
            remove_filter('http_request_args', $enforce, PHP_INT_MAX);
        }

        if (is_wp_error($result)) {
            // Le message de WordPress ne contient ni l'URL complète ni les en-têtes.
            throw new ConnectionException('Network call failed: ' . $result->get_error_message());
        }

        $received = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            $received[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return new Response((int) wp_remote_retrieve_response_code($result), $received, (string) wp_remote_retrieve_body($result));
    }
}
