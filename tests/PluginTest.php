<?php

declare(strict_types=1);

use FasterMessageWP\Admin\SendPage;
use FasterMessageWP\Messaging;
use FasterMessageWP\Settings;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        FmWp::reset();
        Messaging::useTransport(null);
    }

    private function configure(array $overrides = []): void
    {
        FmWp::$options[Settings::OPTION] = array_merge([
            'username' => 'boutique',
            'password' => 's3cret',
            'api_key' => 'fm_live_abc123',
            'base_url' => '',
            'sms_sender' => 'MYSHOP',
            'whatsapp_number' => '+2290161000000',
        ], $overrides);
    }

    /** Réponse simulée de l'API, au format de `wp_remote_request`. */
    private function respond(int $status, array $body, array $headers = []): void
    {
        FmWp::$http = static fn () => [
            'response' => ['code' => $status],
            'headers' => array_merge(['X-Request-Id' => 'req_1'], $headers),
            'body' => json_encode($body),
        ];
    }

    private function lastBody(): array
    {
        return json_decode((string) end(FmWp::$requests)['args']['body'], true);
    }

    /** @return list<string> */
    private function firedActions(): array
    {
        return array_map(static fn ($action) => $action[0], FmWp::$actions);
    }

    // ── Le SDK embarqué ─────────────────────────────────────────────────────

    public function testLeSdkEstEmbarqueSousUnEspaceDeNomsPrefixe(): void
    {
        self::assertTrue(class_exists(FasterMessageWP\Sdk\Client::class));
        self::assertFalse(class_exists('FasterMessage\\Client', false), 'aucune classe du SDK non préfixé ne doit être chargée');

        $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/lib/sdk', FilesystemIterator::SKIP_DOTS));
        foreach ($sources as $file) {
            if ($file->getExtension() === 'php') {
                self::assertDoesNotMatchRegularExpression('/(?<![\w\\\\])FasterMessage\\\\/', file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
    }

    // ── Réglages ────────────────────────────────────────────────────────────

    public function testLeDomaineDApiParDefautEstCeluiDeFasterMessage(): void
    {
        self::assertSame('https://api.fastermessage.com', Settings::get('base_url'));
    }

    public function testUnSecretLaisseVideConserveLaValeurEnregistree(): void
    {
        $this->configure();
        $clean = Settings::sanitize(['username' => ' boutique2 ', 'password' => '', 'api_key' => '']);

        self::assertSame('boutique2', $clean['username']);
        self::assertSame('s3cret', $clean['password']);
        self::assertSame('fm_live_abc123', $clean['api_key']);
        self::assertSame('MYSHOP', $clean['sms_sender'], 'un champ absent du formulaire garde sa valeur');
    }

    public function testUnDomaineDApiEnHttpEstRefuseEtLAncienConserve(): void
    {
        $this->configure(['base_url' => 'https://api.ma-marque.com']);
        $clean = Settings::sanitize(['base_url' => 'http://api.pirate.example']);

        self::assertSame('https://api.ma-marque.com', $clean['base_url']);
        self::assertCount(1, FmWp::$settingsErrors);
    }

    public function testUnDomaineDApiValideEstEnregistreSansBarreFinale(): void
    {
        $this->configure();
        self::assertSame('https://api.ma-marque.com', Settings::sanitize(['base_url' => 'https://api.ma-marque.com/'])['base_url']);
    }

    // ── Envoi d'un SMS ──────────────────────────────────────────────────────

    public function testSansIdentifiantsRienNePartEtUneErreurClaireEstRendue(): void
    {
        $result = fastermessage_send_sms('+2290160000000', 'Bonjour');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('fastermessage_not_configured', $result->get_error_code());
        self::assertSame([], FmWp::$requests);
    }

    public function testUnSmsPartAvecLesIdentifiantsEtLesGardeFousDuSdk(): void
    {
        $this->configure();
        // Un filtre global du site qui couperait la vérification TLS : il doit être ignoré.
        add_filter('http_request_args', static fn (array $args) => ['sslverify' => false, 'redirection' => 5] + $args);
        $this->respond(201, ['id' => 'msg_1', 'status' => 'queued', 'channel' => 'sms']);

        $result = fastermessage_send_sms('+229 01 60 00 00 00', 'Votre commande est prête.', ['client_reference' => 'cmd-42']);

        self::assertSame(['id' => 'msg_1', 'status' => 'queued', 'channel' => 'sms'], $result);
        $request = end(FmWp::$requests);
        self::assertSame('https://api.fastermessage.com/v2/sms/send', $request['url']);
        self::assertSame('POST', $request['args']['method']);
        self::assertSame('Basic ' . base64_encode('boutique:s3cret'), $request['args']['headers']['Authorization']);
        self::assertSame('fm_live_abc123', $request['args']['headers']['X-Api-Key']);
        self::assertStringStartsWith('sdk-', $request['args']['headers']['Idempotency-Key']);
        self::assertSame('fastermessage-wordpress/0.1.0', $request['args']['user-agent']);
        self::assertArrayNotHasKey('User-Agent', $request['args']['headers']);
        self::assertTrue($request['args']['sslverify']);
        self::assertSame(0, $request['args']['redirection']);
        self::assertSame([
            'to' => '+2290160000000',
            'from' => 'MYSHOP',
            'client_reference' => 'cmd-42',
            'content' => 'Votre commande est prête.',
        ], $this->lastBody());
        self::assertContains('fastermessage_message_sent', $this->firedActions());
    }

    public function testUnRefusDeLApiDevientUnWpErrorAuCodeStable(): void
    {
        $this->configure();
        $this->respond(402, ['error' => ['code' => 'insufficient_balance', 'message' => 'Insufficient balance.', 'type' => 'billing_error', 'request_id' => 'req_9']]);

        $result = fastermessage_send_sms('+2290160000000', 'Bonjour');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('insufficient_balance', $result->get_error_code());
        self::assertSame(402, $result->get_error_data()['status']);
        self::assertSame('req_9', $result->get_error_data()['request_id']);
        self::assertCount(1, FmWp::$requests, 'un 402 n’est jamais rejoué');
        self::assertContains('fastermessage_send_failed', $this->firedActions());
    }

    public function testSansExpediteurRienNePart(): void
    {
        $this->configure(['sms_sender' => '']);
        $result = fastermessage_send_sms('+2290160000000', 'Bonjour');

        self::assertSame('fastermessage_missing_from', $result->get_error_code());
        self::assertSame([], FmWp::$requests);
    }

    public function testUnePanneReseauRendLaCleDIdempotenceAReutiliser(): void
    {
        $this->configure();
        add_filter('fastermessage_max_retries', static fn () => 0);
        FmWp::$http = static fn () => new WP_Error('http_request_failed', 'cURL error 28: timeout');

        $result = fastermessage_send_sms('+2290160000000', 'Bonjour', ['idempotency_key' => 'commande-42']);

        self::assertSame('fastermessage_connection', $result->get_error_code());
        self::assertSame('commande-42', $result->get_error_data()['idempotency_key']);
    }

    public function testLeFiltreDesParametresPeutModifierUnEnvoi(): void
    {
        $this->configure();
        add_filter('fastermessage_send_params', static fn (array $params, string $channel) => $channel === 'sms' ? ['from' => 'AUTRE'] + $params : $params, 10, 2);
        $this->respond(201, ['id' => 'msg_2', 'status' => 'queued']);

        fastermessage_send_sms('+2290160000000', 'Bonjour');

        self::assertSame('AUTRE', $this->lastBody()['from']);
    }

    // ── Envoi WhatsApp ──────────────────────────────────────────────────────

    public function testUnTexteLibreWhatsappPartDansContentText(): void
    {
        $this->configure();
        $this->respond(201, ['id' => 'wa_1', 'status' => 'queued', 'channel' => 'whatsapp']);

        fastermessage_send_whatsapp('+2290160000000', 'Merci pour votre message.');

        self::assertSame('https://api.fastermessage.com/v2/whatsapp/send', end(FmWp::$requests)['url']);
        self::assertSame(['text' => 'Merci pour votre message.'], $this->lastBody()['content']);
        self::assertSame('+2290161000000', $this->lastBody()['from']);
    }

    public function testUnModeleWhatsappPartTelQuel(): void
    {
        $this->configure(['base_url' => 'https://api.ma-marque.com']);
        $this->respond(201, ['id' => 'wa_2', 'status' => 'queued']);
        $template = ['template' => 'confirmation_commande', 'language' => 'fr', 'variables' => ['1' => 'Awa', '2' => '1187']];

        fastermessage_send_whatsapp('+2290160000000', $template, ['from' => '+2290162000000']);

        self::assertSame('https://api.ma-marque.com/v2/whatsapp/send', end(FmWp::$requests)['url']);
        self::assertSame($template, $this->lastBody()['content']);
        self::assertSame('+2290162000000', $this->lastBody()['from']);
    }

    public function testLesVariablesDuFormulaireSontLuesLigneALigne(): void
    {
        self::assertSame(
            ['1' => 'Awa', '2' => '1187 = total', 'header' => 'Bonjour'],
            SendPage::parseVariables("1=Awa\r\n2 = 1187 = total\nligne sans égal\n\nheader=Bonjour"),
        );
    }

    // ── E-mail ──────────────────────────────────────────────────────────────

    public function testUnEMailPartSurSaRouteEtGardeLesPointsDeLAdresse(): void
    {
        $this->configure(['email_sender' => 'commandes@maboutique.com']);
        $this->respond(201, ['id' => 'mail_1', 'status' => 'queued', 'channel' => 'email']);

        $result = fastermessage_send_email('awa.koffi@exemple.com', 'Votre commande 1187', '<p>Bonjour Awa</p>', ['text' => 'Bonjour Awa']);

        self::assertSame('mail_1', $result['id']);
        self::assertSame('https://api.fastermessage.com/v2/email/send', end(FmWp::$requests)['url']);
        self::assertSame([
            'to' => 'awa.koffi@exemple.com',
            'from' => 'commandes@maboutique.com',
            'content' => ['subject' => 'Votre commande 1187', 'html' => '<p>Bonjour Awa</p>', 'text' => 'Bonjour Awa'],
        ], $this->lastBody());
    }

    public function testSansAdresseExpeditriceLApiChoisitCelleDuCompte(): void
    {
        $this->configure();
        $this->respond(201, ['id' => 'mail_2', 'status' => 'queued']);

        $result = fastermessage_send_email('awa@exemple.com', 'Objet', '<p>Corps</p>');

        self::assertSame('mail_2', $result['id'], 'pas d’erreur « expéditeur manquant » pour l’e-mail');
        self::assertArrayNotHasKey('from', $this->lastBody());
        self::assertSame(['subject' => 'Objet', 'html' => '<p>Corps</p>'], $this->lastBody()['content']);
    }

    public function testLAdresseExpeditriceParDefautEstNettoyee(): void
    {
        $this->configure();
        self::assertSame('', Settings::sanitize(['email_sender' => 'pas-une-adresse'])['email_sender']);
        self::assertSame('ventes@maboutique.com', Settings::sanitize(['email_sender' => ' ventes@maboutique.com '])['email_sender']);
    }

    public function testLesDerniersMessagesReunissentLesTroisCanaux(): void
    {
        $this->configure();
        $byChannel = [
            'sms' => [['id' => 's1', 'channel' => 'sms', 'created_at' => '2026-11-21T10:00:00Z']],
            'whatsapp' => [['id' => 'w1', 'channel' => 'whatsapp', 'created_at' => '2026-11-21T12:00:00Z']],
            'email' => [['id' => 'e1', 'channel' => 'email', 'created_at' => '2026-11-21T11:00:00Z']],
        ];
        FmWp::$http = static function (string $url) use ($byChannel) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return ['response' => ['code' => 200], 'headers' => [], 'body' => json_encode(['data' => $byChannel[$query['channel']], 'has_more' => false, 'next_cursor' => null])];
        };

        $recent = Messaging::recent(2);

        self::assertCount(3, FmWp::$requests, 'sans canal, l’API ne rendrait que les SMS');
        self::assertSame(['w1', 'e1'], array_column($recent, 'id'), 'du plus récent au plus ancien, borné à la limite');
    }

    // ── Listes WhatsApp ─────────────────────────────────────────────────────

    public function testLesListesWhatsappEcartentLAuthentificationEtSontGardees(): void
    {
        $this->configure();
        $pages = [
            '/v2/whatsapp/numbers' => ['data' => [['number' => '+2290161000000', 'display_name' => 'Boutique']], 'has_more' => false, 'next_cursor' => null],
            '/v2/whatsapp/templates' => ['data' => [
                ['name' => 'confirmation_commande', 'language' => 'fr', 'category' => 'utility', 'variables' => ['1', '2']],
                ['name' => 'code_connexion', 'language' => 'fr', 'category' => 'authentication', 'variables' => []],
            ], 'has_more' => false, 'next_cursor' => null],
        ];
        FmWp::$http = static fn (string $url) => [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => json_encode($pages[(string) parse_url($url, PHP_URL_PATH)]),
        ];

        $catalog = Messaging::whatsappCatalog();

        self::assertSame(['+2290161000000'], array_column($catalog['numbers'], 'number'));
        self::assertSame(['confirmation_commande'], array_column($catalog['templates'], 'name'), 'un modèle d’authentification sert l’OTP, pas l’envoi');
        self::assertCount(2, FmWp::$requests);

        Messaging::whatsappCatalog();
        self::assertCount(2, FmWp::$requests, 'la deuxième lecture vient du cache');

        Messaging::reset();
        self::assertArrayNotHasKey('fastermessage_wa_catalog', FmWp::$transients, 'changer les réglages vide le cache');
    }

    public function testSansLesRoutesDeLectureLEcranRevientALaSaisie(): void
    {
        $this->configure();
        $this->respond(404, ['error' => ['code' => 'not_found', 'message' => 'Route not found.']]);

        $catalog = Messaging::whatsappCatalog();

        self::assertInstanceOf(WP_Error::class, $catalog);
        self::assertArrayNotHasKey('fastermessage_wa_catalog', FmWp::$transients, 'un échec n’est pas mis en cache');
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    public function testLeSoldeSertDeTestDeConnexion(): void
    {
        $this->configure();
        $this->respond(200, ['balances' => [['channel' => 'sms', 'amount' => 1500, 'currency' => 'XOF']]]);

        self::assertSame([['channel' => 'sms', 'amount' => 1500, 'currency' => 'XOF']], Messaging::balance());
        self::assertSame('GET', end(FmWp::$requests)['args']['method']);
        self::assertArrayNotHasKey('body', end(FmWp::$requests)['args']);
    }
}
