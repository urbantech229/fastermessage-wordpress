<?php

declare(strict_types=1);

namespace FasterMessageWP;

use FasterMessageWP\Admin\SendPage;
use FasterMessageWP\Admin\SettingsPage;

/** DÉMARRAGE de l'extension, au crochet `plugins_loaded`. */
final class Plugin
{
    public static function boot(): void
    {
        load_plugin_textdomain('fastermessage', false, dirname(plugin_basename(FASTERMESSAGE_FILE)) . '/languages');

        // Un client construit avec les anciens réglages ne doit pas survivre à leur changement.
        add_action('update_option_' . Settings::OPTION, [Messaging::class, 'reset']);

        if (is_admin()) {
            (new SettingsPage())->register();
            (new SendPage())->register();
        }
    }

    /** Droit requis pour envoyer depuis l'écran d'administration. */
    public static function sendCapability(): string
    {
        /**
         * Droit WordPress requis pour l'écran « Envoyer un message ».
         * Par défaut `manage_options` (administrateurs). Ex. : `edit_others_posts`
         * pour l'ouvrir aux éditeurs.
         */
        return (string) apply_filters('fastermessage_send_capability', 'manage_options');
    }
}
