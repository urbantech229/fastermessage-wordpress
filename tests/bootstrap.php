<?php
/**
 * AMORÇAGE DES TESTS — sans WordPress.
 *
 * Les fonctions de WordPress dont l'extension a besoin sont simulées ici, en
 * mémoire, au plus près de leur comportement réel. On teste ainsi la logique de
 * l'extension (réglages, transport, envois, erreurs) en quelques millisecondes ;
 * l'intégration dans un vrai WordPress s'éprouve à part, dans WordPress Playground.
 *
 * Le fichier principal de l'extension est chargé tel quel : son chargeur
 * automatique est donc éprouvé lui aussi.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

/** État simulé de WordPress, remis à zéro par `FmWp::reset()` avant chaque test. */
final class FmWp
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, array<int, array<int, callable>>> */
    public static array $filters = [];
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public static array $actions = [];
    /** @var list<array{0: string, 1: string, 2: string}> */
    public static array $settingsErrors = [];
    /** @var null|callable(string, array<string, mixed>): mixed */
    public static $http = null;
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$filters = [];
        self::$actions = [];
        self::$settingsErrors = [];
        self::$http = null;
        self::$requests = [];
    }
}

class WP_Error
{
    /** @param mixed $data */
    public function __construct(private string $code = '', private string $message = '', private $data = null)
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    /** @return mixed */
    public function get_error_data()
    {
        return $this->data;
    }
}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/\\') . '/';
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    FmWp::$filters[$hook][$priority][] = $callback;

    return true;
}

function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $args = 1): bool
{
    if (is_callable($callback)) {
        FmWp::$filters[$hook][$priority][] = $callback;
    }

    return true;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    foreach (FmWp::$filters[$hook][$priority] ?? [] as $index => $registered) {
        if ($registered === $callback) {
            unset(FmWp::$filters[$hook][$priority][$index]);

            return true;
        }
    }

    return false;
}

/** @param mixed $value */
function apply_filters(string $hook, $value, ...$args)
{
    $byPriority = FmWp::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
    }

    return $value;
}

function do_action(string $hook, ...$args): void
{
    FmWp::$actions[] = [$hook, $args];
}

/** @param mixed $default */
function get_option(string $name, $default = false)
{
    return FmWp::$options[$name] ?? $default;
}

function get_transient(string $name)
{
    return FmWp::$transients[$name] ?? false;
}

function set_transient(string $name, $value, int $ttl = 0): bool
{
    FmWp::$transients[$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    unset(FmWp::$transients[$name]);

    return true;
}

function get_current_user_id(): int
{
    return 1;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function sanitize_text_field(string $value): string
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($value)) ?? '');
}

function sanitize_email(string $email): string
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    FmWp::$settingsErrors[] = [$setting, $code, $message];
}

/** @return mixed */
function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/** @param array<string, mixed> $args */
function wp_remote_request(string $url, array $args = [])
{
    $args = apply_filters('http_request_args', $args, $url);
    FmWp::$requests[] = ['url' => $url, 'args' => $args];
    if (FmWp::$http === null) {
        return new WP_Error('http_request_failed', 'no HTTP stub');
    }

    return (FmWp::$http)($url, $args);
}

function wp_remote_retrieve_headers($response): array
{
    return $response['headers'] ?? [];
}

function wp_remote_retrieve_response_code($response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string
{
    return (string) ($response['body'] ?? '');
}

require dirname(__DIR__) . '/fastermessage.php';
