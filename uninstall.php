<?php
/**
 * DÉSINSTALLATION — appelé par WordPress quand l'extension est SUPPRIMÉE (pas
 * simplement désactivée).
 *
 * L'extension ne stocke qu'une option, ses réglages, identifiants compris : les
 * laisser après suppression garderait des secrets d'API dans la base d'un site
 * qui ne s'en sert plus. Les messages, eux, vivent chez FasterMessage et restent
 * consultables dans la console.
 *
 * @package FasterMessage
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('fastermessage_settings');
