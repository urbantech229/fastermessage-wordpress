<?php

declare(strict_types=1);

namespace FasterMessageWP;

/**
 * E-MAILS DU SITE PAR FASTERMESSAGE — réglage « Envoyer les e-mails du site par
 * FasterMessage » (`route_wp_mail`), COUPÉ par défaut.
 *
 * Branché sur le filtre `pre_wp_mail` (WordPress 5.7+) : rendre `true` dit à
 * `wp_mail()` que l'e-mail est parti ; rendre `null` le laisse faire son envoi
 * normal (PHPMailer, ou l'extension SMTP du site).
 *
 * ⚠️ LE REPLI EST LA RÈGLE. Une panne de l'API, une adresse expéditrice non
 * validée ou un solde épuisé ne doivent JAMAIS bloquer une réinitialisation de mot
 * de passe : tout ce que FasterMessage n'a pas accepté repart par l'envoi normal.
 *
 * Partent TOUJOURS par l'envoi normal (`null`), sans appel à l'API :
 *  · réglage coupé, ou extension non configurée ;
 *  · pièces jointes (l'API n'en prend pas) ;
 *  · en-têtes `Cc`, `Bcc` (l'API n'en prend pas) ou `Reply-To` (les formulaires de
 *    contact s'en servent pour répondre au visiteur : l'ignorer enverrait la
 *    réponse au site) ;
 *  · corps `multipart/*` déjà construit par une extension ;
 *  · un destinataire illisible ;
 *  · un e-mail écarté par le filtre `fastermessage_route_wp_mail`.
 *
 * PLUSIEURS DESTINATAIRES : un envoi API par destinataire (l'API n'en prend qu'un).
 *  · le PREMIER échoue → `null` : rien n'est parti, WordPress envoie le lot entier ;
 *  · un échec APRÈS au moins un succès → `true` : rendre `null` ferait renvoyer
 *    par WordPress les e-mails déjà partis (doublons). L'échec est signalé par
 *    l'action `wp_mail_failed` (et dans le journal PHP si `WP_DEBUG`).
 *
 * L'expéditeur est l'adresse par défaut des réglages (sinon l'API prend la
 * première adresse active du compte) : l'en-tête `From` et le nom d'expéditeur
 * fixés par WordPress ou une extension sont ignorés — l'API n'envoie que depuis
 * une adresse validée.
 */
final class MailRouter
{
    /** Garde anti-réentrance : un `wp_mail()` appelé PENDANT un envoi part normalement. */
    private static bool $busy = false;

    public static function register(): void
    {
        add_filter('pre_wp_mail', [self::class, 'filter'], 10, 2);
    }

    /**
     * @param null|bool $return valeur rendue par un filtre précédent
     * @param mixed     $atts   `to`, `subject`, `message`, `headers`, `attachments`
     *
     * @return null|bool `true` : parti par FasterMessage ; `null` : envoi normal
     */
    public static function filter($return, $atts = [])
    {
        // Une autre extension a déjà pris l'e-mail en charge : ne pas l'envoyer deux fois.
        if ($return !== null) {
            return $return;
        }
        if (self::$busy || !is_array($atts) || !Settings::isEnabled('route_wp_mail') || !Settings::isConfigured()) {
            return null;
        }

        /**
         * Laisser un e-mail sur l'envoi normal, même réglage activé.
         *
         * @param bool                 $route vrai pour l'envoyer par FasterMessage
         * @param array<string, mixed> $atts  arguments de `wp_mail()`
         */
        if (!apply_filters('fastermessage_route_wp_mail', true, $atts)) {
            return null;
        }

        $mail = self::prepare($atts);
        if ($mail === null) {
            return null;
        }

        self::$busy = true;
        try {
            return self::deliver($mail, $atts);
        } catch (\Throwable $error) {
            // Rien ne doit sortir vers WordPress ; si rien n'est parti, envoi normal.
            return null;
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Ce qui partira par l'API, ou `null` si l'e-mail doit rester sur l'envoi normal.
     *
     * @param array<string, mixed> $atts
     *
     * @return null|array{to: list<string>, subject: string, html: string, text: string}
     */
    public static function prepare(array $atts): ?array
    {
        if (self::attachments($atts['attachments'] ?? []) !== []) {
            return null;
        }

        $contentType = null;
        foreach (self::headerLines($atts['headers'] ?? []) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            if (in_array($name, ['cc', 'bcc', 'reply-to'], true)) {
                return null;
            }
            if ($name === 'content-type') {
                $contentType = strtolower(trim(explode(';', $parts[1], 2)[0]));
            }
        }

        // Même ordre que `wp_mail()` : l'en-tête d'abord, puis le filtre global.
        $contentType = strtolower(trim((string) apply_filters('wp_mail_content_type', $contentType ?? 'text/plain')));
        if (str_starts_with($contentType, 'multipart/')) {
            return null;
        }

        $recipients = self::recipients($atts['to'] ?? '');
        if ($recipients === null) {
            return null;
        }

        $subject = is_scalar($atts['subject'] ?? null) ? (string) $atts['subject'] : '';
        $message = is_scalar($atts['message'] ?? null) ? (string) $atts['message'] : '';
        if ($contentType === 'text/html') {
            return ['to' => $recipients, 'subject' => $subject, 'html' => $message, 'text' => ''];
        }

        // Texte brut : gardé tel quel en version texte, et mis en forme HTML sans
        // rien interpréter (un « <lien> » de réinitialisation reste lisible).
        return ['to' => $recipients, 'subject' => $subject, 'html' => wpautop(esc_html($message)), 'text' => $message];
    }

    /**
     * Adresses des destinataires : tableau ou liste séparée par des virgules,
     * formes `adresse` et `Nom <adresse>`. Une seule illisible → `null`.
     *
     * @param mixed $to
     *
     * @return null|list<string>
     */
    public static function recipients($to): ?array
    {
        $items = is_array($to) ? $to : explode(',', is_scalar($to) ? (string) $to : '');
        $addresses = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                return null;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/<([^<>]+)>\s*$/', $item, $match) === 1) {
                $item = trim($match[1]);
            }
            $address = is_email($item);
            if (!is_string($address) || $address === '') {
                return null;
            }
            $addresses[] = $address;
        }

        return $addresses === [] ? null : array_values(array_unique($addresses));
    }

    /**
     * @param array{to: list<string>, subject: string, html: string, text: string} $mail
     * @param array<string, mixed>                                                 $atts
     *
     * @return null|true
     */
    private static function deliver(array $mail, array $atts): ?bool
    {
        $sent = [];
        $failed = [];
        foreach ($mail['to'] as $address) {
            $result = Messaging::sendEmail($address, $mail['subject'], $mail['html'], ['text' => $mail['text']]);
            if (!is_wp_error($result)) {
                $sent[] = $address;
                continue;
            }
            if ($sent === []) {
                // Rien n'est parti : WordPress envoie normalement, à tous.
                return null;
            }
            $failed[$address] = (string) $result->get_error_code();
        }

        $data = array_merge($atts, ['to' => $sent]);
        // Même action que l'envoi normal : les journaux d'e-mails du site restent complets.
        do_action('wp_mail_succeeded', $data);

        if ($failed !== []) {
            $error = new \WP_Error('wp_mail_failed', sprintf(
                /* translators: 1: number of recipients, 2: error codes. */
                __('FasterMessage could not send this email to %1$s recipient(s) (%2$s). It was not resent through the normal sending, to avoid duplicates for the recipients who already received it.', 'fastermessage'),
                (string) count($failed),
                implode(', ', array_unique($failed)),
            ), array_merge($atts, ['to' => array_keys($failed), 'fastermessage_errors' => $failed]));
            do_action('wp_mail_failed', $error);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Codes d'erreur seulement : aucune adresse dans le journal.
                error_log('FasterMessage wp_mail: ' . count($failed) . ' recipient(s) failed after a partial send (' . implode(', ', array_unique($failed)) . ').');
            }
        }

        return true;
    }

    /**
     * @param mixed $headers chaîne (lignes) ou tableau de lignes, comme `wp_mail()`
     *
     * @return list<string>
     */
    private static function headerLines($headers): array
    {
        if (is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }
        if (!is_array($headers)) {
            return [];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            // Tolère aussi `['Cc' => 'a@b.c']`, que certaines extensions passent.
            $lines[] = is_string($name) ? $name . ': ' . $value : $value;
        }

        return $lines;
    }

    /**
     * @param mixed $attachments
     *
     * @return list<string>
     */
    private static function attachments($attachments): array
    {
        if (is_string($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
        }
        if (!is_array($attachments)) {
            return [];
        }

        return array_values(array_filter($attachments, static fn ($file) => !is_string($file) || trim($file) !== ''));
    }
}
