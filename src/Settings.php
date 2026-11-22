<?php

declare(strict_types=1);

namespace FasterMessageWP;

/**
 * RÉGLAGES de l'extension : une seule option WordPress, `fastermessage_settings`.
 *
 * Chaque réglage peut aussi être imposé par une CONSTANTE dans `wp-config.php`
 * (`FASTERMESSAGE_API_USER`, `FASTERMESSAGE_API_PASSWORD`, `FASTERMESSAGE_API_KEY`,
 * `FASTERMESSAGE_BASE_URL`, `FASTERMESSAGE_SMS_SENDER`, `FASTERMESSAGE_WHATSAPP_NUMBER`,
 * `FASTERMESSAGE_EMAIL_SENDER`).
 * La constante l'emporte, et le champ correspondant devient non modifiable dans
 * l'écran : c'est la façon de garder les secrets hors de la base, et de figer
 * la configuration d'un site géré par une agence.
 *
 * ⚠️ LES SECRETS NE SONT JAMAIS RÉAFFICHÉS. Un champ secret laissé vide à
 * l'enregistrement CONSERVE la valeur déjà enregistrée : c'est ce qui permet
 * d'enregistrer l'écran sans retaper le mot de passe, sans jamais le renvoyer au
 * navigateur.
 */
final class Settings
{
    public const OPTION = 'fastermessage_settings';

    public const DEFAULT_BASE_URL = 'https://api.fastermessage.com';

    /** Réglage → constante qui peut l'imposer. */
    public const CONSTANTS = [
        'username' => 'FASTERMESSAGE_API_USER',
        'password' => 'FASTERMESSAGE_API_PASSWORD',
        'api_key' => 'FASTERMESSAGE_API_KEY',
        'base_url' => 'FASTERMESSAGE_BASE_URL',
        'sms_sender' => 'FASTERMESSAGE_SMS_SENDER',
        'whatsapp_number' => 'FASTERMESSAGE_WHATSAPP_NUMBER',
        'email_sender' => 'FASTERMESSAGE_EMAIL_SENDER',
    ];

    /** Réglages jamais renvoyés au navigateur. */
    public const SECRETS = ['password', 'api_key'];

    /** Valeur effective d'un réglage : constante, puis option, puis défaut. */
    public static function get(string $key): string
    {
        $constant = self::CONSTANTS[$key] ?? null;
        if ($constant !== null && defined($constant)) {
            return trim((string) constant($constant));
        }

        $stored = get_option(self::OPTION, []);
        $value = is_array($stored) && is_string($stored[$key] ?? null) ? trim($stored[$key]) : '';

        if ($value === '' && $key === 'base_url') {
            return self::DEFAULT_BASE_URL;
        }

        return $value;
    }

    /** Vrai si le réglage est imposé par `wp-config.php`. */
    public static function isLocked(string $key): bool
    {
        $constant = self::CONSTANTS[$key] ?? null;

        return $constant !== null && defined($constant);
    }

    /** Les trois identifiants d'API sont renseignés. */
    public static function isConfigured(): bool
    {
        return self::get('username') !== '' && self::get('password') !== '' && self::get('api_key') !== '';
    }

    /** Clé de test `fm_test_` : les messages sont simulés, rien n'est envoyé ni débité. */
    public static function isTestMode(): bool
    {
        return str_contains(self::get('api_key'), '_test_');
    }

    /**
     * Nettoyage à l'enregistrement de l'écran (`register_setting`).
     *
     * Rend un tableau complet : un réglage absent du formulaire garde sa valeur.
     * Un domaine d'API refusé est signalé et l'ancien est conservé, plutôt que
     * d'enregistrer une adresse qui ferait échouer tous les envois.
     *
     * @param mixed $input
     *
     * @return array<string, string>
     */
    public static function sanitize($input): array
    {
        $previous = get_option(self::OPTION, []);
        $previous = is_array($previous) ? $previous : [];
        $input = is_array($input) ? $input : [];
        $clean = [];

        foreach (array_keys(self::CONSTANTS) as $key) {
            $old = is_string($previous[$key] ?? null) ? $previous[$key] : '';
            $raw = isset($input[$key]) && is_string($input[$key]) ? trim(wp_unslash($input[$key])) : null;

            if ($raw === null || ($raw === '' && in_array($key, self::SECRETS, true))) {
                $clean[$key] = $old;
                continue;
            }

            $clean[$key] = match ($key) {
                'base_url' => self::cleanBaseUrl($raw, $old),
                'email_sender' => sanitize_email($raw),
                // Une clé ou un mot de passe collé garde ses caractères : seuls les
                // blancs de bord, ajoutés par le copier-coller, sont retirés.
                'password', 'api_key' => $raw,
                default => sanitize_text_field($raw),
            };
        }

        return $clean;
    }

    private static function cleanBaseUrl(string $raw, string $old): string
    {
        if ($raw === '') {
            return '';
        }
        if (self::isValidBaseUrl($raw)) {
            return rtrim($raw, '/');
        }

        add_settings_error(
            self::OPTION,
            'fastermessage_base_url',
            __('The API domain must be an https address, without credentials or query string. The previous value was kept.', 'fastermessage'),
        );

        return $old;
    }

    /** Même règle que le SDK : https, sans identifiants ; http seulement vers la machine locale. */
    public static function isValidBaseUrl(string $value): bool
    {
        $parts = wp_parse_url($value);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $allowed = $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));

        return $allowed && $host !== '' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
    }
}
