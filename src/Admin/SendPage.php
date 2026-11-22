<?php

declare(strict_types=1);

namespace FasterMessageWP\Admin;

use FasterMessageWP\Messaging;
use FasterMessageWP\Plugin;
use FasterMessageWP\Settings;

/**
 * ÉCRAN FasterMessage › Envoyer un message : SMS, WhatsApp (modèle approuvé ou
 * texte libre dans la fenêtre de 24 h) ou e-mail (rédigé ou modèle publié), puis
 * les derniers messages du compte.
 *
 * Les expéditeurs et les modèles sont proposés en LISTES quand l'API les sert
 * (ce que l'envoi accepte, rien d'autre) ; sinon, la saisie libre reste possible.
 */
final class SendPage
{
    public const SLUG = 'fastermessage';

    private const ACTION = 'fastermessage_send';

    private const FORM_TTL = 60;

    /** Champs du formulaire, gardés après un refus pour ne rien faire retaper. */
    private const FIELDS = ['channel', 'to', 'from', 'wa_from', 'wa_mode', 'template', 'language', 'variables', 'content', 'email_from', 'email_mode', 'email_template', 'subject'];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Send a message', 'fastermessage'),
            'FasterMessage',
            Plugin::sendCapability(),
            self::SLUG,
            [$this, 'render'],
            'dashicons-email-alt',
            81,
        );
        add_submenu_page(self::SLUG, __('Send a message', 'fastermessage'), __('Send a message', 'fastermessage'), Plugin::sendCapability(), self::SLUG, [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::sendCapability())) {
            return;
        }
        $form = $this->restoreForm();
        $channel = in_array($form['channel'], Messaging::CHANNELS, true) ? $form['channel'] : 'sms';
        $waMode = $form['wa_mode'] === 'text' ? 'text' : 'template';
        $emailMode = $form['email_mode'] === 'template' ? 'template' : 'compose';

        // Listes de ce qui est utilisable. Indisponibles (API plus ancienne, panne) :
        // l'écran garde la saisie libre, l'envoi reste possible.
        $configured = Settings::isConfigured();
        $waCatalog = $configured ? Messaging::whatsappCatalog() : null;
        $numbers = is_array($waCatalog) ? $waCatalog['numbers'] : [];
        $templates = is_array($waCatalog) ? $waCatalog['templates'] : [];
        $emailCatalog = $configured ? Messaging::emailCatalog() : null;
        $senders = is_array($emailCatalog) ? $emailCatalog['senders'] : [];
        $emailTemplates = is_array($emailCatalog) ? $emailCatalog['templates'] : [];

        $defaultNumber = $form['wa_from'] !== '' ? $form['wa_from'] : Settings::get('whatsapp_number');
        $defaultSender = $form['email_from'] !== '' ? $form['email_from'] : Settings::get('email_sender');
        ?>
        <div class="wrap fastermessage-send" data-wa-list="<?php echo $numbers !== [] ? '1' : '0'; ?>">
            <h1><?php esc_html_e('Send a message', 'fastermessage'); ?></h1>
            <?php Notice::render(); ?>

            <?php if (!$configured) : ?>
                <div class="notice notice-error inline"><p>
                    <?php
                    printf(
                        /* translators: %s: link to the settings screen. */
                        esc_html__('Enter your API credentials first in %s.', 'fastermessage'),
                        '<a href="' . esc_url(admin_url('options-general.php?page=' . SettingsPage::SLUG)) . '">' . esc_html__('Settings › FasterMessage', 'fastermessage') . '</a>',
                    );
                    ?>
                </p></div>
            <?php elseif (Settings::isTestMode()) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Test mode: your fm_test_ key simulates every message. Nothing is sent or charged.', 'fastermessage'); ?></p></div>
            <?php endif; ?>

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Channel', 'fastermessage'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="channel" value="sms" <?php checked($channel, 'sms'); ?>> SMS</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="channel" value="whatsapp" <?php checked($channel, 'whatsapp'); ?>> WhatsApp</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="channel" value="email" <?php checked($channel, 'email'); ?>> <?php esc_html_e('Email', 'fastermessage'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fm-to"><?php esc_html_e('Recipient', 'fastermessage'); ?></label></th>
                        <td>
                            <input type="text" id="fm-to" name="to" class="regular-text" required value="<?php echo esc_attr($form['to']); ?>"
                                data-phone="+2290160000000" data-email="awa@exemple.com">
                            <p class="description fm-hide-email"><?php esc_html_e('International format, with the country code.', 'fastermessage'); ?></p>
                        </td>
                    </tr>

                    <?php /* ── Expéditeur SMS (et WhatsApp sans liste) ── */ ?>
                    <tr class="fm-from-row">
                        <th scope="row"><label for="fm-from"><?php esc_html_e('Sender', 'fastermessage'); ?></label></th>
                        <td>
                            <input type="text" id="fm-from" name="from" class="regular-text" value="<?php echo esc_attr($form['from']); ?>"
                                data-sms="<?php echo esc_attr(Settings::get('sms_sender')); ?>"
                                data-whatsapp="<?php echo esc_attr(Settings::get('whatsapp_number')); ?>"
                                placeholder="<?php echo esc_attr(Settings::get('sms_sender')); ?>">
                            <p class="description"><?php esc_html_e('Leave empty to use the default sender from the settings.', 'fastermessage'); ?></p>
                        </td>
                    </tr>

                    <?php /* ── WhatsApp ── */ ?>
                    <?php if ($numbers !== []) : ?>
                    <tr class="fm-only-whatsapp">
                        <th scope="row"><label for="fm-wa-from"><?php esc_html_e('WhatsApp number', 'fastermessage'); ?></label></th>
                        <td>
                            <select id="fm-wa-from" name="wa_from">
                                <?php foreach ($numbers as $number) :
                                    $value = (string) ($number['number'] ?? '');
                                    $label = trim((string) ($number['display_name'] ?? '') . ' ' . $value);
                                    ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $defaultNumber); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Only the numbers that can send right now are listed.', 'fastermessage'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr class="fm-only-whatsapp">
                        <th scope="row"><?php esc_html_e('WhatsApp content', 'fastermessage'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="wa_mode" value="template" <?php checked($waMode, 'template'); ?>> <?php esc_html_e('Approved template', 'fastermessage'); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="wa_mode" value="text" <?php checked($waMode, 'text'); ?>> <?php esc_html_e('Free text (24-hour window)', 'fastermessage'); ?></label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Free text is only accepted if the recipient wrote to this number in the last 24 hours. Otherwise, use an approved template.', 'fastermessage'); ?></p>
                        </td>
                    </tr>
                    <tr class="fm-only-template">
                        <th scope="row"><label for="fm-template"><?php esc_html_e('Template name', 'fastermessage'); ?></label></th>
                        <td>
                            <?php if ($templates !== []) : ?>
                                <select id="fm-template" name="template">
                                    <option value=""><?php esc_html_e('Choose an approved template', 'fastermessage'); ?></option>
                                    <?php foreach ($templates as $template) :
                                        $name = (string) ($template['name'] ?? '');
                                        $language = (string) ($template['language'] ?? '');
                                        ?>
                                        <option value="<?php echo esc_attr($name); ?>"
                                            data-language="<?php echo esc_attr($language); ?>"
                                            data-variables="<?php echo esc_attr(implode(',', array_map('strval', (array) ($template['variables'] ?? [])))); ?>"
                                            data-body="<?php echo esc_attr((string) ($template['body'] ?? '')); ?>"
                                            <?php selected($name . '|' . $language, $form['template'] . '|' . $form['language']); ?>><?php echo esc_html($name . ($language !== '' ? ' (' . $language . ')' : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" id="fm-template-body"></p>
                            <?php else : ?>
                                <input type="text" id="fm-template" name="template" class="regular-text" value="<?php echo esc_attr($form['template']); ?>" placeholder="confirmation_commande">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="fm-only-template">
                        <th scope="row"><label for="fm-language"><?php esc_html_e('Language', 'fastermessage'); ?></label></th>
                        <td>
                            <input type="text" id="fm-language" name="language" class="small-text" value="<?php echo esc_attr($form['language']); ?>" placeholder="fr">
                            <p class="description"><?php esc_html_e('Optional if the template exists in one language only.', 'fastermessage'); ?></p>
                        </td>
                    </tr>
                    <tr class="fm-only-template">
                        <th scope="row"><label for="fm-variables"><?php esc_html_e('Variables', 'fastermessage'); ?></label></th>
                        <td>
                            <textarea id="fm-variables" name="variables" rows="3" class="large-text code" placeholder="1=Awa&#10;2=1187"><?php echo esc_textarea($form['variables']); ?></textarea>
                            <p class="description"><?php esc_html_e('One per line: 1=value for {{1}}, 2=value for {{2}}… header=value for a text header.', 'fastermessage'); ?></p>
                        </td>
                    </tr>

                    <?php /* ── E-mail ── */ ?>
                    <tr class="fm-only-email">
                        <th scope="row"><label for="fm-email-from"><?php esc_html_e('Sender address', 'fastermessage'); ?></label></th>
                        <td>
                            <?php if ($senders !== []) : ?>
                                <select id="fm-email-from" name="email_from">
                                    <?php foreach ($senders as $sender) :
                                        $address = (string) ($sender['address'] ?? '');
                                        $label = trim((string) ($sender['name'] ?? '') . ' <' . $address . '>');
                                        ?>
                                        <option value="<?php echo esc_attr($address); ?>" <?php selected($address, $defaultSender); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Only the validated addresses of your account are listed.', 'fastermessage'); ?></p>
                            <?php else : ?>
                                <input type="email" id="fm-email-from" name="email_from" class="regular-text" value="<?php echo esc_attr($form['email_from']); ?>" placeholder="<?php echo esc_attr(Settings::get('email_sender')); ?>">
                                <p class="description"><?php esc_html_e('Leave empty to use the first active address of your account.', 'fastermessage'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($emailTemplates !== []) : ?>
                    <tr class="fm-only-email">
                        <th scope="row"><?php esc_html_e('Email content', 'fastermessage'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="email_mode" value="compose" <?php checked($emailMode, 'compose'); ?>> <?php esc_html_e('Write a message', 'fastermessage'); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="email_mode" value="template" <?php checked($emailMode, 'template'); ?>> <?php esc_html_e('Published template', 'fastermessage'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr class="fm-only-email-template">
                        <th scope="row"><label for="fm-email-template"><?php esc_html_e('Template', 'fastermessage'); ?></label></th>
                        <td>
                            <select id="fm-email-template" name="email_template">
                                <?php foreach ($emailTemplates as $template) :
                                    $id = (string) ($template['id'] ?? '');
                                    ?>
                                    <option value="<?php echo esc_attr($id); ?>" data-subject="<?php echo esc_attr((string) ($template['subject'] ?? '')); ?>" <?php selected($id, $form['email_template']); ?>><?php echo esc_html((string) ($template['name'] ?? $id)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" id="fm-email-template-subject"></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr class="fm-only-email-compose">
                        <th scope="row"><label for="fm-subject"><?php esc_html_e('Subject', 'fastermessage'); ?></label></th>
                        <td><input type="text" id="fm-subject" name="subject" class="large-text" value="<?php echo esc_attr($form['subject']); ?>"></td>
                    </tr>

                    <?php /* ── Texte : SMS, WhatsApp libre, e-mail rédigé ── */ ?>
                    <tr class="fm-only-text">
                        <th scope="row"><label for="fm-content"><?php esc_html_e('Message', 'fastermessage'); ?></label></th>
                        <td><textarea id="fm-content" name="content" rows="5" class="large-text"><?php echo esc_textarea($form['content']); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button(__('Send', 'fastermessage'), 'primary', 'submit', true, $configured ? [] : ['disabled' => 'disabled']); ?>
            </form>

            <?php $this->renderRecent(); ?>
        </div>
        <?php
        $this->printScript();
    }

    /** Derniers messages du compte, lus à l'API : ce sont eux qui font foi, pas un journal local. */
    private function renderRecent(): void
    {
        if (!Settings::isConfigured()) {
            return;
        }
        echo '<h2>' . esc_html__('Latest messages', 'fastermessage') . '</h2>';

        $messages = Messaging::recent(10);
        if (is_wp_error($messages)) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: error message. */
                __('The latest messages could not be loaded: %s', 'fastermessage'),
                $messages->get_error_message(),
            )) . '</p>';
            return;
        }
        if ($messages === []) {
            echo '<p>' . esc_html__('No message sent yet.', 'fastermessage') . '</p>';
            return;
        }

        $labels = [
            'scheduled' => __('Scheduled', 'fastermessage'),
            'queued' => __('Queued', 'fastermessage'),
            'sent' => __('Sent', 'fastermessage'),
            'delivered' => __('Delivered', 'fastermessage'),
            'undelivered' => __('Undelivered', 'fastermessage'),
            'failed' => __('Failed', 'fastermessage'),
        ];
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Date', 'fastermessage'); ?></th>
                <th><?php esc_html_e('Channel', 'fastermessage'); ?></th>
                <th><?php esc_html_e('Recipient', 'fastermessage'); ?></th>
                <th><?php esc_html_e('Status', 'fastermessage'); ?></th>
                <th><?php esc_html_e('Identifier', 'fastermessage'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($messages as $message) :
                $status = (string) ($message['status'] ?? '');
                $created = isset($message['created_at']) ? strtotime((string) $message['created_at']) : false;
                ?>
                <tr>
                    <td><?php echo esc_html($created ? wp_date(get_option('date_format') . ' H:i', $created) : '—'); ?></td>
                    <td><?php echo esc_html((string) ($message['channel'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($message['to'] ?? '')); ?></td>
                    <td><?php echo esc_html($labels[$status] ?? $status); ?></td>
                    <td><code><?php echo esc_html((string) ($message['id'] ?? '')); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function handle(): void
    {
        if (!current_user_can(Plugin::sendCapability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'fastermessage'), 403);
        }
        check_admin_referer(self::ACTION);

        $form = [];
        foreach (self::FIELDS as $field) {
            $raw = isset($_POST[$field]) ? wp_unslash((string) $_POST[$field]) : '';
            $form[$field] = in_array($field, ['content', 'variables'], true) ? sanitize_textarea_field($raw) : sanitize_text_field($raw);
        }

        $result = match ($form['channel']) {
            'whatsapp' => $this->sendWhatsapp($form),
            'email' => $this->sendEmail($form),
            default => Messaging::sendSms($form['to'], $form['content'], ['from' => $form['from']]),
        };

        if (is_wp_error($result)) {
            // La saisie est gardée : un numéro mal tapé ne doit pas tout faire retaper.
            set_transient($this->formKey(), $form, self::FORM_TTL);
            Notice::flash('error', sprintf(
                /* translators: 1: error message, 2: error code. */
                __('Message refused: %1$s (%2$s)', 'fastermessage'),
                esc_html($result->get_error_message()),
                '<code>' . esc_html((string) $result->get_error_code()) . '</code>',
            ));
        } else {
            Notice::flash('success', sprintf(
                /* translators: 1: message identifier, 2: status. */
                __('Message accepted: %1$s (status %2$s).', 'fastermessage'),
                '<code>' . esc_html((string) ($result['id'] ?? '')) . '</code>',
                esc_html((string) ($result['status'] ?? '')),
            ));
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>|\WP_Error
     */
    private function sendWhatsapp(array $form)
    {
        // Le numéro choisi dans la liste l'emporte sur la saisie libre.
        $args = ['from' => $form['wa_from'] !== '' ? $form['wa_from'] : $form['from']];
        $content = $form['wa_mode'] === 'text'
            ? $form['content']
            : array_filter([
                'template' => $form['template'],
                'language' => $form['language'],
                'variables' => self::parseVariables($form['variables']),
            ], static fn ($value) => $value !== '' && $value !== []);

        return Messaging::sendWhatsapp($form['to'], $content, $args);
    }

    /**
     * Un modèle e-mail est relu À L'API par son identifiant, jamais repris du
     * navigateur : le formulaire ne transporte pas de HTML, et ce qui part est bien
     * le modèle publié.
     *
     * @param array<string, string> $form
     *
     * @return array<string, mixed>|\WP_Error
     */
    private function sendEmail(array $form)
    {
        $args = ['from' => $form['email_from']];

        if ($form['email_mode'] === 'template' && $form['email_template'] !== '') {
            $catalog = Messaging::emailCatalog();
            if (is_wp_error($catalog)) {
                return $catalog;
            }
            $template = null;
            foreach ($catalog['templates'] as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $form['email_template']) {
                    $template = $candidate;
                    break;
                }
            }
            if ($template === null) {
                return new \WP_Error('fastermessage_template_unavailable', __('This email template is no longer available. Reload the page and choose another one.', 'fastermessage'));
            }
            if ($args['from'] === '' && !empty($template['from_email'])) {
                $args['from'] = (string) $template['from_email'];
            }

            return Messaging::sendEmail($form['to'], (string) ($template['subject'] ?? ''), (string) ($template['html'] ?? ''), $args);
        }

        // Message rédigé dans l'écran : du texte, mis en forme HTML sans rien interpréter.
        $args['text'] = $form['content'];

        return Messaging::sendEmail($form['to'], $form['subject'], wpautop(esc_html($form['content'])), $args);
    }

    /**
     * `1=Awa` / `2=1187` / `header=Bonjour` → `['1' => 'Awa', '2' => '1187', 'header' => 'Bonjour']`.
     *
     * @return array<string, string>
     */
    public static function parseVariables(string $raw): array
    {
        $variables = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = trim($parts[0]);
            if ($name !== '') {
                $variables[$name] = trim($parts[1]);
            }
        }

        return $variables;
    }

    /** @return array<string, string> */
    private function restoreForm(): array
    {
        $defaults = array_fill_keys(self::FIELDS, '');
        $saved = get_transient($this->formKey());
        if (!is_array($saved)) {
            return $defaults;
        }
        delete_transient($this->formKey());

        return array_merge($defaults, array_map('strval', array_intersect_key($saved, $defaults)));
    }

    private function formKey(): string
    {
        return 'fastermessage_form_' . get_current_user_id();
    }

    /** Affiche les champs du canal choisi. Quelques lignes : pas de fichier à mettre en file. */
    private function printScript(): void
    {
        $script = <<<'JS'
(function () {
  var root = document.querySelector('.fastermessage-send');
  if (!root) return;
  var waList = root.dataset.waList === '1';
  var to = root.querySelector('#fm-to');
  var from = root.querySelector('#fm-from');
  var picker = root.querySelector('select#fm-template');
  var language = root.querySelector('#fm-language');
  var variables = root.querySelector('#fm-variables');
  var body = root.querySelector('#fm-template-body');
  var emailPicker = root.querySelector('#fm-email-template');
  var emailSubject = root.querySelector('#fm-email-template-subject');
  function value(name) {
    var checked = root.querySelector('input[name="' + name + '"]:checked');
    return checked ? checked.value : '';
  }
  function toggle(selector, visible) {
    root.querySelectorAll(selector).forEach(function (row) { row.hidden = !visible; });
  }
  function refresh() {
    var channel = value('channel') || 'sms';
    var whatsapp = channel === 'whatsapp';
    var email = channel === 'email';
    var waTemplate = whatsapp && value('wa_mode') !== 'text';
    var emailTemplate = email && value('email_mode') === 'template';
    toggle('.fm-from-row', channel === 'sms' || (whatsapp && !waList));
    toggle('.fm-only-whatsapp', whatsapp);
    toggle('.fm-only-template', waTemplate);
    toggle('.fm-only-email', email);
    toggle('.fm-only-email-template', emailTemplate);
    toggle('.fm-only-email-compose', email && !emailTemplate);
    toggle('.fm-only-text', !waTemplate && !emailTemplate);
    toggle('.fm-hide-email', !email);
    to.type = email ? 'email' : 'text';
    to.placeholder = to.dataset[email ? 'email' : 'phone'];
    from.placeholder = from.dataset[whatsapp ? 'whatsapp' : 'sms'] || '';
  }
  // Un modèle WhatsApp choisi dans la liste : sa langue, son texte, et une ligne
  // par variable attendue — il ne reste qu'à compléter les valeurs.
  function describe(prefill) {
    if (!picker) return;
    var option = picker.options[picker.selectedIndex];
    var chosen = option && option.value !== '';
    language.value = chosen ? (option.dataset.language || '') : language.value;
    language.readOnly = chosen;
    body.textContent = chosen ? (option.dataset.body || '') : '';
    if (prefill && chosen) {
      var keys = (option.dataset.variables || '').split(',').filter(Boolean);
      variables.value = keys.map(function (key) { return key + '='; }).join('\n');
    }
  }
  function describeEmail() {
    if (!emailPicker) return;
    var option = emailPicker.options[emailPicker.selectedIndex];
    emailSubject.textContent = option ? (option.dataset.subject || '') : '';
  }
  root.addEventListener('change', function (event) {
    if (event.target === picker) describe(true);
    if (event.target === emailPicker) describeEmail();
    refresh();
  });
  refresh();
  describe(false);
  describeEmail();
})();
JS;
        wp_print_inline_script_tag($script);
    }
}
