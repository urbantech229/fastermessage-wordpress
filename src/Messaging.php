<?php

declare(strict_types=1);

namespace FasterMessageWP;

use FasterMessageWP\Http\WpTransport;
use FasterMessageWP\Sdk\Client;
use FasterMessageWP\Sdk\Exception\ApiException;
use FasterMessageWP\Sdk\Exception\ConnectionException;
use FasterMessageWP\Sdk\Http\Transport;

/**
 * ENVOIS — la seule porte entre WordPress et le SDK.
 *
 * Tout ce qui part vers l'API passe ici, qu'il vienne de l'écran d'envoi ou du
 * code d'un thème : mêmes réglages, mêmes crochets, même traduction des erreurs.
 *
 * ⚠️ AUCUNE EXCEPTION NE SORT D'ICI. Une extension WordPress qui laisse filer une
 * exception provoque un écran blanc sur le site du client — pour un SMS refusé.
 * Chaque échec devient un `WP_Error`, la convention de WordPress, que l'appelant
 * teste avec `is_wp_error()`.
 */
final class Messaging
{
    private static ?Client $client = null;

    private static ?Transport $transport = null;

    private const CATALOG_KEY = 'fastermessage_wa_catalog';

    private const EMAIL_CATALOG_KEY = 'fastermessage_email_catalog';

    /** Canaux servis par l'extension — ceux que « Derniers messages » réunit. */
    public const CHANNELS = ['sms', 'whatsapp', 'email'];

    /**
     * Client du SDK construit sur les réglages, ou `WP_Error` s'ils sont incomplets.
     *
     * Délai court (15 s) et une seule reprise : un envoi lancé depuis une page ne
     * doit pas bloquer le visiteur une minute derrière une API qui ne répond pas.
     *
     * @return Client|\WP_Error
     */
    public static function client()
    {
        if (self::$client !== null) {
            return self::$client;
        }
        if (!Settings::isConfigured()) {
            return new \WP_Error('fastermessage_not_configured', __('FasterMessage is not configured: enter your API username, password and key in Settings › FasterMessage.', 'fastermessage'));
        }

        try {
            self::$client = new Client(Settings::get('username'), Settings::get('password'), Settings::get('api_key'), [
                'base_url' => Settings::get('base_url'),
                'timeout' => (float) apply_filters('fastermessage_timeout', 15.0),
                'max_retries' => (int) apply_filters('fastermessage_max_retries', 1),
                'transport' => self::$transport ?? new WpTransport(),
            ]);
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('fastermessage_invalid_settings', $error->getMessage());
        }

        return self::$client;
    }

    /**
     * Envoie un SMS.
     *
     * @param array{from?: string, client_reference?: string, callback_url?: string, idempotency_key?: string} $args
     *
     * @return array<string, mixed>|\WP_Error la ressource message (`id`, `status`…)
     */
    public static function sendSms(string $to, string $content, array $args = [])
    {
        $params = self::baseParams($to, $args, 'sms_sender');
        $params['content'] = $content;

        return self::send('sms', $params, $args['idempotency_key'] ?? null);
    }

    /**
     * Envoie un message WhatsApp.
     *
     * `$content` : un texte libre (chaîne, seulement dans les 24 h qui suivent un
     * message du destinataire) ou un modèle approuvé
     * (`['template' => 'nom', 'language' => 'fr', 'variables' => ['1' => '…']]`).
     *
     * @param string|array<string, mixed> $content
     * @param array{from?: string, client_reference?: string, callback_url?: string, idempotency_key?: string} $args
     *
     * @return array<string, mixed>|\WP_Error
     */
    public static function sendWhatsapp(string $to, $content, array $args = [])
    {
        $params = self::baseParams($to, $args, 'whatsapp_number');
        $params['content'] = is_string($content) ? ['text' => $content] : $content;

        return self::send('whatsapp', $params, $args['idempotency_key'] ?? null);
    }

    /**
     * Envoie un e-mail.
     *
     * `$html` : le corps HTML. `$args['text']` : sa version texte, facultative.
     * `from` : une adresse VALIDÉE du compte ; absente (ni argument ni réglage),
     * l'API prend la première adresse active du compte.
     *
     * @param array{from?: string, text?: string, client_reference?: string, callback_url?: string, idempotency_key?: string} $args
     *
     * @return array<string, mixed>|\WP_Error
     */
    public static function sendEmail(string $to, string $subject, string $html, array $args = [])
    {
        $params = self::baseParams($to, $args, 'email_sender');
        $params['content'] = array_filter([
            'subject' => $subject,
            'html' => $html,
            'text' => (string) ($args['text'] ?? ''),
        ], static fn (string $value) => $value !== '');

        return self::send('email', $params, $args['idempotency_key'] ?? null);
    }

    /**
     * Adresses expéditrices et modèles e-mail UTILISABLES du compte — mêmes règles
     * et même cache que `whatsappCatalog`.
     *
     * @return array{senders: list<array<string, mixed>>, templates: list<array<string, mixed>>}|\WP_Error
     */
    public static function emailCatalog()
    {
        $cached = get_transient(self::EMAIL_CATALOG_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $catalog = self::call(static fn (Client $client) => [
            'senders' => iterator_to_array($client->email->allSenders(['limit' => 200]), false),
            'templates' => iterator_to_array($client->email->allTemplates(['limit' => 200]), false),
        ]);
        if (!is_wp_error($catalog)) {
            set_transient(self::EMAIL_CATALOG_KEY, $catalog, 5 * MINUTE_IN_SECONDS);
        }

        return $catalog;
    }

    /**
     * Derniers messages du compte, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>|\WP_Error
     */
    public static function recent(int $limit = 10)
    {
        // ⚠️ Sans `channel`, l'API ne rend que les SMS : une lecture par canal, puis
        // fusion par date. Trois appels courts valent mieux qu'un écran qui tairait
        // les envois WhatsApp et e-mail.
        return self::call(static function (Client $client) use ($limit) {
            $limit = max(1, min(100, $limit));
            $messages = [];
            foreach (self::CHANNELS as $channel) {
                $page = $client->messages->list(['channel' => $channel, 'limit' => $limit]);
                array_push($messages, ...(is_array($page['data'] ?? null) ? array_values($page['data']) : []));
            }
            usort($messages, static fn (array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

            return array_slice($messages, 0, $limit);
        });
    }

    /**
     * Numéros et modèles WhatsApp UTILISABLES du compte — ce que l'envoi accepte —,
     * pour proposer des listes plutôt qu'une saisie.
     *
     * Gardés 5 minutes : l'écran d'envoi s'affiche souvent, et ces listes changent
     * rarement (un modèle met des heures à être approuvé par Meta). Vidé quand les
     * réglages changent (`reset`).
     *
     * @return array{numbers: list<array<string, mixed>>, templates: list<array<string, mixed>>}|\WP_Error
     *         `WP_Error` si l'API ne les sert pas encore : l'écran revient à la saisie.
     */
    public static function whatsappCatalog()
    {
        $cached = get_transient(self::CATALOG_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $catalog = self::call(static fn (Client $client) => [
            'numbers' => iterator_to_array($client->whatsapp->allNumbers(['limit' => 200]), false),
            // Les modèles d'authentification servent l'OTP, jamais l'envoi d'un message.
            'templates' => array_values(array_filter(
                iterator_to_array($client->whatsapp->allTemplates(['limit' => 200]), false),
                static fn (array $template) => ($template['category'] ?? '') !== 'authentication',
            )),
        ]);
        if (!is_wp_error($catalog)) {
            set_transient(self::CATALOG_KEY, $catalog, 5 * MINUTE_IN_SECONDS);
        }

        return $catalog;
    }

    /**
     * Soldes du compte, par canal — sert aussi de test de connexion.
     *
     * @return list<array<string, mixed>>|\WP_Error
     */
    public static function balance()
    {
        return self::call(static fn (Client $client) => $client->account->balance());
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function baseParams(string $to, array $args, string $defaultFrom): array
    {
        $params = [
            // Un numéro perd ses espaces, points, tirets et parenthèses ; une adresse
            // e-mail, elle, garde ses points.
            'to' => $defaultFrom === 'email_sender' ? trim($to) : preg_replace('/[\s.\-()]/', '', $to),
            'from' => trim((string) ($args['from'] ?? '')) ?: Settings::get($defaultFrom),
        ];
        foreach (['client_reference', 'callback_url'] as $optional) {
            if (isset($args[$optional]) && $args[$optional] !== '') {
                $params[$optional] = (string) $args[$optional];
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|\WP_Error
     */
    private static function send(string $channel, array $params, ?string $idempotencyKey)
    {
        /**
         * Filtre les paramètres d'un envoi juste avant l'appel à l'API.
         *
         * @param array<string, mixed> $params  champs de l'API v2 (`to`, `from`, `content`…)
         * @param string               $channel `sms` ou `whatsapp`
         */
        $params = (array) apply_filters('fastermessage_send_params', $params, $channel);

        // Réglages incomplets d'abord : c'est le geste à faire en premier, avant
        // même de choisir un expéditeur.
        $client = self::client();
        if (is_wp_error($client)) {
            do_action('fastermessage_send_failed', $client, $channel, $params);

            return $client;
        }

        if ($channel === 'email' && ($params['from'] ?? '') === '') {
            // Sans adresse demandée, l'API choisit la première adresse active du compte.
            unset($params['from']);
        } elseif (($params['from'] ?? '') === '') {
            $error = new \WP_Error('fastermessage_missing_from', $channel === 'sms'
                ? __('No SMS sender: pass "from" or set a default sender in the settings.', 'fastermessage')
                : __('No WhatsApp number: pass "from" or set a default WhatsApp number in the settings.', 'fastermessage'));
            do_action('fastermessage_send_failed', $error, $channel, $params);

            return $error;
        }

        $options = $idempotencyKey !== null && $idempotencyKey !== '' ? ['idempotency_key' => $idempotencyKey] : [];
        $result = self::call(static fn (Client $client) => match ($channel) {
            'sms' => $client->sms->send($params, $options),
            'email' => $client->email->send($params, $options),
            default => $client->whatsapp->send($params, $options),
        });

        if (is_wp_error($result)) {
            /**
             * Un envoi a été refusé (réglages, API, réseau).
             *
             * @param \WP_Error            $error   code = code d'erreur stable de l'API
             * @param string               $channel `sms` ou `whatsapp`
             * @param array<string, mixed> $params
             */
            do_action('fastermessage_send_failed', $result, $channel, $params);

            return $result;
        }

        /**
         * Un message a été accepté par l'API (statut `queued`, en général).
         *
         * @param array<string, mixed> $message ressource message de l'API
         * @param string               $channel `sms` ou `whatsapp`
         * @param array<string, mixed> $params
         */
        do_action('fastermessage_message_sent', $result, $channel, $params);

        return $result;
    }

    /**
     * Exécute un appel du SDK et traduit ses exceptions en `WP_Error`.
     *
     * Code du `WP_Error` = code d'erreur STABLE de l'API (`insufficient_balance`,
     * `invalid_phone_number`, `session_window_closed`…), à tester plutôt que le
     * message. Les données portent le statut HTTP, le champ en cause et
     * l'identifiant de requête, à citer au support.
     *
     * @return mixed|\WP_Error
     */
    private static function call(callable $operation)
    {
        $client = self::client();
        if (is_wp_error($client)) {
            return $client;
        }

        try {
            return $operation($client);
        } catch (ApiException $error) {
            return new \WP_Error($error->errorCode, $error->getMessage(), [
                'status' => $error->httpStatus,
                'param' => $error->param,
                'request_id' => $error->requestId,
            ]);
        } catch (ConnectionException $error) {
            // Le message a PU partir : un nouvel essai doit reprendre la même clé.
            return new \WP_Error('fastermessage_connection', $error->getMessage(), [
                'idempotency_key' => $error->idempotencyKey,
            ]);
        } catch (\Throwable $error) {
            return new \WP_Error('fastermessage_error', $error->getMessage());
        }
    }

    /**
     * Remplace le transport HTTP et oublie le client construit.
     *
     * @internal tests uniquement
     */
    public static function useTransport(?Transport $transport): void
    {
        self::$transport = $transport;
        self::$client = null;
    }

    /** Oublie le client construit et les listes lues — après un changement de réglages. */
    public static function reset(): void
    {
        self::$client = null;
        delete_transient(self::CATALOG_KEY);
        delete_transient(self::EMAIL_CATALOG_KEY);
    }
}
