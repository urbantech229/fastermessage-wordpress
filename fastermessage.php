<?php
/**
 * Plugin Name:       FasterMessage
 * Plugin URI:        https://www.fastermessage.com/docs
 * Description:       Send SMS and WhatsApp messages from WordPress with the FasterMessage API v2: from the admin screen or from your own code.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Urban Technology
 * Author URI:        https://www.fastermessage.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       fastermessage
 * Domain Path:       /languages
 *
 * @package FasterMessage
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('FASTERMESSAGE_VERSION', '0.1.0');
define('FASTERMESSAGE_FILE', __FILE__);
define('FASTERMESSAGE_DIR', plugin_dir_path(__FILE__));

/*
 * Chargement automatique, sans Composer sur le site : l'extension s'installe par
 * simple dépôt du dossier. Deux racines seulement, toutes deux sous un espace de
 * noms propre à l'extension — le SDK y est embarqué préfixé (voir
 * `bin/embarquer-sdk.php`), pour ne jamais entrer en conflit avec une autre copie.
 */
spl_autoload_register(static function (string $class): void {
    $roots = [
        'FasterMessageWP\\Sdk\\' => FASTERMESSAGE_DIR . 'lib/sdk/',
        'FasterMessageWP\\' => FASTERMESSAGE_DIR . 'src/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require FASTERMESSAGE_DIR . 'src/functions.php';

add_action('plugins_loaded', [FasterMessageWP\Plugin::class, 'boot']);
