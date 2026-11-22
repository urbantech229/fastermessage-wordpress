<?php

declare(strict_types=1);

namespace FasterMessageWP\Admin;

/**
 * MESSAGE À USAGE UNIQUE après une redirection.
 *
 * Les formulaires de l'extension sont traités par `admin-post.php` puis
 * redirigent vers leur écran (POST → redirection → GET) : recharger la page ne
 * renvoie pas le SMS une seconde fois. Le compte rendu traverse la redirection
 * dans un transient propre à l'utilisateur, lu puis effacé à l'affichage.
 */
final class Notice
{
    private const TTL = 60;

    public static function flash(string $type, string $message): void
    {
        set_transient(self::key(), ['type' => $type, 'message' => $message], self::TTL);
    }

    public static function render(): void
    {
        $notice = get_transient(self::key());
        if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
            return;
        }
        delete_transient(self::key());

        $type = in_array($notice['type'], ['success', 'error', 'warning', 'info'], true) ? $notice['type'] : 'info';
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            wp_kses($notice['message'], ['strong' => [], 'code' => [], 'br' => []]),
        );
    }

    private static function key(): string
    {
        return 'fastermessage_notice_' . get_current_user_id();
    }
}
