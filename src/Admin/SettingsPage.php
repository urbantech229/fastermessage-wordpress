<?php

declare(strict_types=1);

namespace FasterMessageWP\Admin;

use FasterMessageWP\Messaging;
use FasterMessageWP\Settings;

/** ÉCRAN Réglages › FasterMessage : identifiants, domaine d'API, expéditeurs par défaut. */
final class SettingsPage
{
    public const SLUG = 'fastermessage-settings';

    private const TEST_ACTION = 'fastermessage_test';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'fields']);
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'testConnection']);
        add_filter('plugin_action_links_' . plugin_basename(FASTERMESSAGE_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        add_options_page(
            __('FasterMessage settings', 'fastermessage'),
            'FasterMessage',
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    /** @param array<int|string, string> $links */
    public function actionLinks(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::SLUG)),
            esc_html__('Settings', 'fastermessage'),
        ));

        return $links;
    }

    public function fields(): void
    {
        register_setting(self::SLUG, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => [],
            // Les secrets n'ont rien à faire dans l'API REST de WordPress.
            'show_in_rest' => false,
        ]);

        add_settings_section('fastermessage_api', __('API credentials', 'fastermessage'), function (): void {
            echo '<p>' . esc_html__('Find them in your FasterMessage console, under Integration & API. A fm_test_ key simulates every message: nothing is sent or charged.', 'fastermessage') . '</p>';
        }, self::SLUG);

        $this->field('username', __('API username', 'fastermessage'), 'fastermessage_api');
        $this->field('password', __('API password', 'fastermessage'), 'fastermessage_api', 'password');
        $this->field('api_key', __('API key', 'fastermessage'), 'fastermessage_api', 'password');
        $this->field('base_url', __('API domain', 'fastermessage'), 'fastermessage_api', 'url', __('Leave the default unless your provider gave you its own API domain.', 'fastermessage'));

        add_settings_section('fastermessage_defaults', __('Default senders', 'fastermessage'), '__return_false', self::SLUG);
        $this->field('sms_sender', __('SMS sender', 'fastermessage'), 'fastermessage_defaults', 'text', __('A sender ID validated on your account, e.g. MYSHOP.', 'fastermessage'));
        $this->field('whatsapp_number', __('WhatsApp number', 'fastermessage'), 'fastermessage_defaults', 'text', __('The WhatsApp number connected to your account, in international format, e.g. +2290161000000.', 'fastermessage'));
        $this->field('email_sender', __('Email sender', 'fastermessage'), 'fastermessage_defaults', 'email', __('A validated sender address of your account. Leave empty to use the first active address of your account.', 'fastermessage'));

        add_settings_section('fastermessage_wp_mail', __('Site emails', 'fastermessage'), function (): void {
            echo '<p>' . esc_html__('When enabled, the emails your site sends through wp_mail() (password resets, new account notifications, WooCommerce emails, contact forms…) go out through FasterMessage, from the default email sender above.', 'fastermessage') . '</p>';
            echo '<p>' . esc_html__('These emails always keep going out through the normal sending of your site: emails with attachments, emails with Cc or Bcc recipients or a Reply-To address, and any email FasterMessage refuses (API unreachable, sender not validated, insufficient balance…). Nothing is lost: a password reset is never blocked.', 'fastermessage') . '</p>';
            echo '<p>' . esc_html__('The sender name and From address set by WordPress or other plugins are not used: FasterMessage only sends from a validated address of your account. Messages are charged like any FasterMessage email.', 'fastermessage') . '</p>';
        }, self::SLUG);
        $this->field('route_wp_mail', __('Send the site emails through FasterMessage', 'fastermessage'), 'fastermessage_wp_mail', 'checkbox', __('Use FasterMessage for the emails sent by WordPress and plugins', 'fastermessage'));
    }

    private function field(string $key, string $label, string $section, string $type = 'text', string $help = ''): void
    {
        add_settings_field('fastermessage_' . $key, $label, function () use ($key, $type, $help): void {
            $id = 'fastermessage_' . $key;
            $name = Settings::OPTION . '[' . $key . ']';
            $locked = Settings::isLocked($key);
            $secret = in_array($key, Settings::SECRETS, true);
            $hasValue = Settings::get($key) !== '';

            if ($type === 'checkbox') {
                // Le champ caché envoie « 0 » quand la case est décochée : sans lui, une
                // case décochée serait absente du formulaire et garderait sa valeur.
                printf(
                    '<input type="hidden" name="%1$s" value="0"><label for="%2$s"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s %4$s> %5$s</label>',
                    esc_attr($name),
                    esc_attr($id),
                    checked(Settings::isEnabled($key), true, false),
                    disabled($locked, true, false),
                    esc_html($help),
                );
                if ($locked) {
                    /* translators: %s: PHP constant name. */
                    echo '<p class="description">' . sprintf(esc_html__('Set by the %s constant in wp-config.php.', 'fastermessage'), '<code>' . esc_html(Settings::CONSTANTS[$key]) . '</code>') . '</p>';
                }

                return;
            }

            // ⚠️ Un secret n'est JAMAIS renvoyé au navigateur, pas même masqué :
            // le champ reste vide, et le laisser vide conserve la valeur enregistrée.
            $value = $secret ? '' : ($key === 'base_url' && !$locked && !$this->stored($key) ? '' : Settings::get($key));
            $placeholder = match (true) {
                $secret && $hasValue => __('Saved — leave empty to keep it', 'fastermessage'),
                $key === 'base_url' => Settings::DEFAULT_BASE_URL,
                default => '',
            };

            printf(
                '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s" class="regular-text" autocomplete="%6$s" %7$s>',
                esc_attr($type),
                esc_attr($id),
                esc_attr($name),
                esc_attr($value),
                esc_attr($placeholder),
                $secret ? 'new-password' : 'off',
                disabled($locked, true, false),
            );
            if ($locked) {
                /* translators: %s: PHP constant name. */
                echo '<p class="description">' . sprintf(esc_html__('Set by the %s constant in wp-config.php.', 'fastermessage'), '<code>' . esc_html(Settings::CONSTANTS[$key]) . '</code>') . '</p>';
            } elseif ($help !== '') {
                echo '<p class="description">' . esc_html($help) . '</p>';
            }
        }, self::SLUG, $section, ['label_for' => 'fastermessage_' . $key]);
    }

    private function stored(string $key): bool
    {
        $stored = get_option(Settings::OPTION, []);

        return is_array($stored) && ($stored[$key] ?? '') !== '';
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('FasterMessage settings', 'fastermessage'); ?></h1>
            <?php Notice::render(); ?>
            <?php if (Settings::isConfigured() && Settings::isTestMode()) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Test mode: your fm_test_ key simulates every message. Nothing is sent or charged.', 'fastermessage'); ?></p></div>
            <?php endif; ?>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::SLUG);
                do_settings_sections(self::SLUG);
                submit_button();
                ?>
            </form>

            <h2><?php esc_html_e('Connection test', 'fastermessage'); ?></h2>
            <p><?php esc_html_e('Checks your credentials by reading your account balance. Nothing is sent.', 'fastermessage'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ACTION); ?>">
                <?php wp_nonce_field(self::TEST_ACTION); ?>
                <?php submit_button(__('Test the connection', 'fastermessage'), 'secondary', 'submit', false, Settings::isConfigured() ? [] : ['disabled' => 'disabled']); ?>
            </form>
        </div>
        <?php
    }

    public function testConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'fastermessage'), 403);
        }
        check_admin_referer(self::TEST_ACTION);

        $balances = Messaging::balance();
        if (is_wp_error($balances)) {
            Notice::flash('error', sprintf(
                /* translators: 1: error message, 2: error code. */
                __('Connection failed: %1$s (%2$s)', 'fastermessage'),
                esc_html($balances->get_error_message()),
                '<code>' . esc_html((string) $balances->get_error_code()) . '</code>',
            ));
        } else {
            $parts = [];
            foreach ($balances as $balance) {
                $parts[] = esc_html(sprintf('%s : %s %s', $balance['channel'] ?? '?', number_format_i18n((float) ($balance['amount'] ?? 0), 2), $balance['currency'] ?? ''));
            }
            Notice::flash('success', __('Connection successful.', 'fastermessage') . ($parts !== [] ? '<br>' . implode('<br>', $parts) : ''));
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        exit;
    }
}
