=== FasterMessage ===
Contributors: urbantechnology
Tags: sms, whatsapp, notifications, messaging, otp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Send SMS and WhatsApp messages from WordPress with the FasterMessage API v2.

== Description ==

FasterMessage is a messaging platform for SMS, WhatsApp and email. This plugin connects your WordPress site to your FasterMessage account:

* **Settings**: your API credentials, your API domain (if your provider gave you one), a default SMS sender, WhatsApp number and email sender address. A "Test the connection" button checks everything by reading your balance.
* **Send from the admin screen**: an SMS, a WhatsApp message (approved template, or free text within the 24-hour window) or an email (written on screen, or a published template), and the list of your latest messages on all three channels with their status. WhatsApp numbers and templates, email addresses and templates are offered as lists: only what your account can actually use.
* **Send from your code**: `fastermessage_send_sms()`, `fastermessage_send_whatsapp()` and `fastermessage_send_email()` return the message, or a `WP_Error` — never an exception. A `fallback` argument lets the API try another channel if the message fails (for example an SMS when a WhatsApp message cannot be delivered).
* **Site emails through FasterMessage** (optional, off by default): the emails your site sends with `wp_mail()` go out through FasterMessage. Emails with attachments, Cc, Bcc or Reply-To, and any email FasterMessage refuses, keep going out through the normal sending: a password reset is never blocked.

A `fm_test_` key simulates every message: nothing is sent or charged. Use it to try the plugin.

The plugin only uses the FasterMessage API v2. Your credentials never leave your server except towards your API domain, over HTTPS, with certificate verification and without following redirects.

== Installation ==

1. Upload the `fastermessage` folder to `/wp-content/plugins/`, or install the zip from Plugins › Add New › Upload.
2. Activate the plugin.
3. Go to Settings › FasterMessage, enter your API username, password and key (FasterMessage console, Integration & API), then click "Test the connection".

To keep secrets out of the database, define them in `wp-config.php` instead:

`define('FASTERMESSAGE_API_USER', '…');`
`define('FASTERMESSAGE_API_PASSWORD', '…');`
`define('FASTERMESSAGE_API_KEY', 'fm_live_…');`

Also available: `FASTERMESSAGE_BASE_URL`, `FASTERMESSAGE_SMS_SENDER`, `FASTERMESSAGE_WHATSAPP_NUMBER`, `FASTERMESSAGE_EMAIL_SENDER`, `FASTERMESSAGE_ROUTE_WP_MAIL` (`true` or `false`). A constant takes precedence and locks the matching field.

== Frequently Asked Questions ==

= How do I send an SMS from my theme? =

`$message = fastermessage_send_sms('+2290160000000', 'Your order is ready.');`
`if (is_wp_error($message)) { error_log($message->get_error_code()); }`

The error code is the stable API code (`insufficient_balance`, `invalid_phone_number`…).

= How do I send a WhatsApp template? =

`fastermessage_send_whatsapp('+2290160000000', ['template' => 'order_confirmation', 'language' => 'fr', 'variables' => ['1' => 'Awa', '2' => '1187']]);`

= How do I send an email? =

`fastermessage_send_email('awa@example.com', 'Your order 1187', '<p>Hello Awa…</p>', ['text' => 'Hello Awa…']);`

The sender is a validated address of your account (`from`, or the default from the settings). Without one, the API uses the first active address of your account.

= Can the emails of my site go out through FasterMessage? =

Yes, with the "Send the site emails through FasterMessage" option (Settings › FasterMessage, off by default). Every email sent with `wp_mail()` then goes out through FasterMessage, from your default email sender, except:

* emails with attachments, Cc or Bcc recipients, or a Reply-To address (contact forms): the API does not support them;
* any email FasterMessage refuses (API unreachable, sender not validated, insufficient balance…): WordPress sends it normally, so nothing is lost.

With several recipients, one email is sent per recipient. If the first one fails, WordPress sends the whole email normally. If a later one fails, the email is not resent (the first recipients already received it): the failure is reported by the `wp_mail_failed` action. The `fastermessage_route_wp_mail` filter keeps a given email on the normal sending.

= How do I send a WhatsApp message with an SMS fallback? =

`fastermessage_send_whatsapp('+2290160000000', ['template' => 'order_ready', 'language' => 'fr'], ['fallback' => [['channel' => 'sms', 'to' => '+2290160000000', 'from' => 'MYSHOP', 'content' => 'Your order 1187 is ready.']]]);`

= Which hooks are available? =

* `fastermessage_message_sent` (action): a message was accepted by the API.
* `fastermessage_send_failed` (action): a message was refused.
* `fastermessage_send_params` (filter): change the API parameters before sending.
* `fastermessage_route_wp_mail` (filter): return `false` to keep an email on the normal sending.
* `fastermessage_send_capability` (filter): capability required for the send screen (default `manage_options`).
* `fastermessage_timeout`, `fastermessage_max_retries` (filters): HTTP timeout (15 s) and retries (1).

== Changelog ==

= 0.2.0 =
* New: optional "Send the site emails through FasterMessage" setting, with automatic fallback to the normal sending.
* New: `fallback` argument for `fastermessage_send_sms()`, `fastermessage_send_whatsapp()` and `fastermessage_send_email()`.
* Required by the FasterMessage for WooCommerce plugin.

= 0.1.0 =
* First release: settings, connection test, sending SMS, WhatsApp and email from the admin screen and from code, lists of usable senders and templates, latest messages, French translation.
