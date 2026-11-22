<?php
/**
 * FONCTIONS PUBLIQUES de l'extension — l'envoi par code, depuis un thème ou une
 * autre extension.
 *
 * Elles rendent la ressource de l'API (tableau) ou un `WP_Error`, jamais une
 * exception :
 *
 *     $message = fastermessage_send_sms('+2290160000000', 'Votre commande est prête.');
 *     if (is_wp_error($message)) {
 *         error_log($message->get_error_code()); // ex. insufficient_balance
 *     }
 *
 * Chaque fonction est protégée par `function_exists` : un site peut en fournir sa
 * propre version avant le chargement de l'extension.
 *
 * @package FasterMessage
 */

declare(strict_types=1);

use FasterMessageWP\Messaging;
use FasterMessageWP\Settings;

if (!function_exists('fastermessage_send_sms')) {
    /**
     * Envoie un SMS.
     *
     * @param string $to      numéro au format international (`+229…`)
     * @param string $content texte du message
     * @param array{from?: string, client_reference?: string, callback_url?: string, idempotency_key?: string, fallback?: list<array<string, mixed>>} $args
     *        `from` : expéditeur validé (défaut : celui des réglages)
     *        `fallback` : étapes de repli si le SMS échoue, ex. `[['channel' => 'email', 'to' => 'awa@exemple.com']]`
     *
     * @return array<string, mixed>|WP_Error
     */
    function fastermessage_send_sms(string $to, string $content, array $args = [])
    {
        return Messaging::sendSms($to, $content, $args);
    }
}

if (!function_exists('fastermessage_send_whatsapp')) {
    /**
     * Envoie un message WhatsApp : un modèle approuvé, ou un texte libre dans les
     * 24 h qui suivent un message du destinataire.
     *
     *     fastermessage_send_whatsapp('+2290160000000', [
     *         'template' => 'confirmation_commande',
     *         'language' => 'fr',
     *         'variables' => ['1' => 'Awa', '2' => '1187'],
     *     ]);
     *
     * @param string                      $to
     * @param string|array<string, mixed> $content texte libre, ou modèle
     * @param array{from?: string, client_reference?: string, callback_url?: string, idempotency_key?: string, fallback?: list<array<string, mixed>>} $args
     *        `from` : numéro WhatsApp raccordé au compte (défaut : celui des réglages)
     *        `fallback` : étapes de repli, ex. un SMS si le WhatsApp échoue :
     *        `[['channel' => 'sms', 'to' => '+2290160000000', 'from' => 'MYSHOP', 'content' => 'Votre commande 1187 est prête.']]`
     *
     * @return array<string, mixed>|WP_Error
     */
    function fastermessage_send_whatsapp(string $to, $content, array $args = [])
    {
        return Messaging::sendWhatsapp($to, $content, $args);
    }
}

if (!function_exists('fastermessage_send_email')) {
    /**
     * Envoie un e-mail par FasterMessage (domaine authentifié, suivi des remises).
     *
     *     fastermessage_send_email('awa@exemple.com', 'Votre commande 1187', '<p>Bonjour Awa…</p>', [
     *         'text' => 'Bonjour Awa…',            // version texte, facultative
     *         'from' => 'commandes@maboutique.com', // adresse validée ; défaut : réglages, puis compte
     *         'reply_to' => 'support@maboutique.com', // adresse de réponse, facultative
     *     ]);
     *
     * @param array{from?: string, text?: string, reply_to?: string, client_reference?: string, callback_url?: string, idempotency_key?: string, fallback?: list<array<string, mixed>>} $args
     *
     * @return array<string, mixed>|WP_Error
     */
    function fastermessage_send_email(string $to, string $subject, string $html, array $args = [])
    {
        return Messaging::sendEmail($to, $subject, $html, $args);
    }
}

if (!function_exists('fastermessage_is_configured')) {
    /** Les identifiants d'API sont renseignés (rien n'est vérifié auprès de l'API). */
    function fastermessage_is_configured(): bool
    {
        return Settings::isConfigured();
    }
}

if (!function_exists('fastermessage_client')) {
    /**
     * Client du SDK PHP, pour tout ce que les fonctions ci-dessus ne couvrent pas
     * (e-mail, OTP, contacts…). ⚠️ Ses méthodes lèvent des exceptions : à entourer
     * d'un `try`.
     *
     * @return \FasterMessageWP\Sdk\Client|WP_Error
     */
    function fastermessage_client()
    {
        return Messaging::client();
    }
}
