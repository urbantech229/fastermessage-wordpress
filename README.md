# FasterMessage pour WordPress

Extension WordPress officielle de [FasterMessage](https://www.fastermessage.com) : envoyez des SMS, des messages WhatsApp et des e-mails depuis l'administration de votre site ou depuis votre propre code, avec l'API v2.

WordPress 6.5 ou plus récent, PHP 8.1 ou plus récent. Aucune dépendance à installer : le [SDK PHP officiel](https://github.com/urbantech229/fastermessage-php) est embarqué.

## Ce que fait l'extension

| Besoin | Où |
|---|---|
| Identifiants, domaine d'API, expéditeurs par défaut, test de connexion | Réglages › FasterMessage |
| Envoyer un SMS, un message WhatsApp (modèle approuvé ou texte libre dans les 24 h) ou un e-mail (rédigé ou modèle publié) | Menu FasterMessage › Envoyer un message |
| Choisir parmi ce que votre compte peut utiliser : numéros et modèles WhatsApp, adresses et modèles e-mail | Même écran, en listes |
| Suivre les derniers messages des trois canaux et leur statut | Même écran, sous le formulaire |
| Envoyer depuis un thème ou une extension | `fastermessage_send_sms()`, `fastermessage_send_whatsapp()`, `fastermessage_send_email()` |

Une clé `fm_test_` simule chaque message : rien n'est envoyé ni débité.

## Installation

1. Téléchargez l'archive de la [dernière version](https://github.com/urbantech229/fastermessage-wordpress/releases) et installez-la depuis Extensions › Ajouter › Téléverser, ou déposez le dossier dans `wp-content/plugins/fastermessage`.
2. Activez l'extension.
3. Dans Réglages › FasterMessage, renseignez votre identifiant, votre mot de passe et votre clé d'API (console FasterMessage, rubrique Intégration & API), puis cliquez sur « Tester la connexion ».

### Garder les secrets hors de la base

Chaque réglage peut être défini dans `wp-config.php`. La constante l'emporte et verrouille le champ :

```php
define('FASTERMESSAGE_API_USER', 'mon-identifiant');
define('FASTERMESSAGE_API_PASSWORD', '…');
define('FASTERMESSAGE_API_KEY', 'fm_live_…');
define('FASTERMESSAGE_BASE_URL', 'https://api.votre-marque.com'); // si votre fournisseur vous en a donné un
define('FASTERMESSAGE_SMS_SENDER', 'MABOUTIQUE');
define('FASTERMESSAGE_WHATSAPP_NUMBER', '+2290161000000');
define('FASTERMESSAGE_EMAIL_SENDER', 'commandes@maboutique.com');
```

## Envoyer par code

Les fonctions rendent la ressource message de l'API, ou un `WP_Error` — jamais une exception :

```php
$message = fastermessage_send_sms('+2290160000000', 'Votre commande est prête.', [
    'client_reference' => 'cmd-42',          // facultatif, votre référence
    'from' => 'MABOUTIQUE',                  // facultatif : expéditeur par défaut sinon
]);

if (is_wp_error($message)) {
    // Code STABLE de l'API : insufficient_balance, invalid_phone_number, invalid_sender…
    error_log($message->get_error_code());
} else {
    echo $message['id'], ' ', $message['status']; // queued
}
```

WhatsApp, avec un modèle approuvé (à tout moment) ou un texte libre (seulement si le destinataire a écrit à ce numéro dans les 24 h, sinon `session_window_closed`) :

```php
fastermessage_send_whatsapp('+2290160000000', [
    'template' => 'confirmation_commande',
    'language' => 'fr',
    'variables' => ['1' => 'Awa', '2' => '1187'], // {{1}}, {{2}}… ; 'header', 'button_1'…
]);

fastermessage_send_whatsapp('+2290160000000', 'Merci pour votre message, nous revenons vers vous.');
```

E-mail, depuis une adresse validée de votre compte (`from`, sinon celle des réglages, sinon la première adresse active du compte) :

```php
fastermessage_send_email('awa@exemple.com', 'Votre commande 1187', '<p>Bonjour Awa, votre commande est prête.</p>', [
    'text' => 'Bonjour Awa, votre commande est prête.', // version texte, facultative
]);
```

L'extension ne remplace pas `wp_mail()` : les autres e-mails du site partent comme avant.

Une coupure réseau rend `fastermessage_connection` : le message a pu partir. Pour réessayer sans risque de doublon, passez la même `idempotency_key`, rendue dans `$error->get_error_data()['idempotency_key']`.

Pour le reste de l'API (e-mail, OTP, contacts), `fastermessage_client()` rend le client du SDK. ⚠️ Ses méthodes lèvent des exceptions : entourez-les d'un `try`.

## Crochets

| Crochet | Type | Rôle |
|---|---|---|
| `fastermessage_message_sent` | action | Un message a été accepté par l'API : `($message, $channel, $params)`. |
| `fastermessage_send_failed` | action | Un envoi a été refusé : `($error, $channel, $params)`. |
| `fastermessage_send_params` | filtre | Modifier les paramètres juste avant l'appel : `($params, $channel)`. |
| `fastermessage_send_capability` | filtre | Droit requis pour l'écran d'envoi (défaut `manage_options`). |
| `fastermessage_timeout` / `fastermessage_max_retries` | filtres | Délai HTTP (15 s) et reprises (1). |

## Sécurité

- Les identifiants ne partent que vers le domaine d'API, en HTTPS, certificat vérifié, sans suivre de redirection — y compris si un autre filtre du site tente de désactiver la vérification.
- Le mot de passe et la clé ne sont jamais réaffichés dans l'écran des réglages ; ils sont effacés à la suppression de l'extension.
- Les écrans sont réservés aux administrateurs et protégés par jeton (nonce).

## Développement

```bash
composer install
composer test
```

Le SDK est embarqué sous l'espace de noms `FasterMessageWP\Sdk`, depuis une version publiée, pour ne jamais entrer en conflit avec une autre copie du SDK chargée sur le même site :

```bash
php bin/embarquer-sdk.php ../fastermessage-php v0.3.0
```

## Licence

MIT — Urban Technology.
