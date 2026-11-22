<?php

declare(strict_types=1);

use FasterMessageWP\MailRouter;
use FasterMessageWP\Messaging;
use FasterMessageWP\Settings;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * E-MAILS DU SITE PAR FASTERMESSAGE (`pre_wp_mail`) : `true` = parti par l'API,
 * `null` = WordPress fait son envoi normal. Chaque cas de repli est éprouvé.
 */
final class MailRouterTest extends TestCase
{
    protected function setUp(): void
    {
        FmWp::reset();
        Messaging::useTransport(null);
    }

    private function enable(array $overrides = []): void
    {
        FmWp::$options[Settings::OPTION] = array_merge([
            'username' => 'boutique',
            'password' => 's3cret',
            'api_key' => 'fm_live_abc123',
            'email_sender' => 'site@maboutique.com',
            'route_wp_mail' => '1',
        ], $overrides);
    }

    /** @param list<int> $statuses statut HTTP de chaque appel successif */
    private function respondInSequence(array $statuses): void
    {
        FmWp::$http = static function () use (&$statuses) {
            $status = array_shift($statuses) ?? 201;
            $body = $status < 300
                ? ['id' => 'mail_' . count(FmWp::$requests), 'status' => 'queued', 'channel' => 'email']
                : ['error' => ['code' => 'invalid_sender', 'message' => 'Sender not validated.']];

            return ['response' => ['code' => $status], 'headers' => [], 'body' => json_encode($body)];
        };
    }

    /** @return array<string, mixed> */
    private function mail(array $overrides = []): array
    {
        return array_merge([
            'to' => 'awa@exemple.com',
            'subject' => 'Réinitialisation du mot de passe',
            'message' => "Bonjour,\n\nPour réinitialiser : <https://maboutique.com/wp-login.php?action=rp&key=abc>",
            'headers' => '',
            'attachments' => [],
        ], $overrides);
    }

    /** @return list<array<string, mixed>> corps JSON des appels à l'API */
    private function bodies(): array
    {
        return array_map(static fn ($request) => json_decode((string) $request['args']['body'], true), FmWp::$requests);
    }

    /** @return list<string> */
    private function fired(string $hook): array
    {
        return array_values(array_filter(FmWp::$actions, static fn ($action) => $action[0] === $hook));
    }

    // ── Les cas qui restent sur l'envoi normal ──────────────────────────────

    public function testReglageCoupeParDefautLEnvoiResteNormal(): void
    {
        $this->enable(['route_wp_mail' => '']);
        $this->respondInSequence([201]);

        self::assertNull(MailRouter::filter(null, $this->mail()));
        self::assertSame([], FmWp::$requests);
    }

    public function testExtensionNonConfigureeLEnvoiResteNormal(): void
    {
        $this->enable(['api_key' => '']);

        self::assertNull(MailRouter::filter(null, $this->mail()));
        self::assertSame([], FmWp::$requests);
    }

    public function testUneDecisionDUnFiltrePrecedentEstRespectee(): void
    {
        $this->enable();

        self::assertFalse(MailRouter::filter(false, $this->mail()));
        self::assertTrue(MailRouter::filter(true, $this->mail()));
        self::assertSame([], FmWp::$requests);
    }

    public function testUnePieceJointeLaisseLEnvoiNormal(): void
    {
        $this->enable();

        self::assertNull(MailRouter::filter(null, $this->mail(['attachments' => ['/tmp/facture.pdf']])));
        self::assertNull(MailRouter::filter(null, $this->mail(['attachments' => "/tmp/facture.pdf\n"])));
        self::assertSame([], FmWp::$requests);
    }

    public function testCcEtBccLaissentLEnvoiNormal(): void
    {
        $this->enable();

        self::assertNull(MailRouter::filter(null, $this->mail(['headers' => "From: Site <site@x.com>\r\nCc: chef@exemple.com"])));
        self::assertNull(MailRouter::filter(null, $this->mail(['headers' => ['BCC: archive@exemple.com']])));
        self::assertSame([], FmWp::$requests);
    }

    public function testUnReplyToAmbiguLaisseLEnvoiNormal(): void
    {
        $this->enable();

        // Plusieurs adresses, une adresse illisible, ou deux en-têtes : la réponse se perdrait.
        self::assertNull(MailRouter::filter(null, $this->mail(['headers' => ['Reply-To: a@exemple.com, b@exemple.com']])));
        self::assertNull(MailRouter::filter(null, $this->mail(['headers' => ['Reply-To: pas une adresse']])));
        self::assertNull(MailRouter::filter(null, $this->mail(['headers' => ['Reply-To: a@exemple.com', 'Reply-To: b@exemple.com']])));
        self::assertSame([], FmWp::$requests);
    }

    public function testUnReplyToEstTransmisALApi(): void
    {
        $this->enable();
        $this->respondInSequence([201, 201]);

        // Forme simple (formulaire de contact), puis la forme de WooCommerce.
        self::assertTrue(MailRouter::filter(null, $this->mail(['headers' => ['Reply-To' => 'visiteur@exemple.com']])));
        self::assertTrue(MailRouter::filter(null, $this->mail([
            'message' => '<p>Nouvelle commande</p>',
            'headers' => "Content-Type: text/html\r\nReply-to: Awa Client <awa.client@exemple.com>\r\n",
        ])));

        self::assertSame('visiteur@exemple.com', $this->bodies()[0]['content']['reply_to']);
        self::assertSame('awa.client@exemple.com', $this->bodies()[1]['content']['reply_to']);
    }

    public function testSansReplyToLeContenuNEnPortePas(): void
    {
        $this->enable();
        $this->respondInSequence([201]);

        MailRouter::filter(null, $this->mail());

        self::assertArrayNotHasKey('reply_to', $this->bodies()[0]['content']);
    }

    public function testUnCorpsMultipartLaisseLEnvoiNormal(): void
    {
        $this->enable();

        self::assertNull(MailRouter::filter(null, $this->mail(['headers' => 'Content-Type: multipart/alternative; boundary="x"'])));
        self::assertSame([], FmWp::$requests);
    }

    public function testUnDestinataireIllisibleLaisseLEnvoiNormalPourTous(): void
    {
        $this->enable();

        self::assertNull(MailRouter::filter(null, $this->mail(['to' => 'awa@exemple.com, pas-une-adresse'])));
        self::assertNull(MailRouter::filter(null, $this->mail(['to' => ''])));
        self::assertSame([], FmWp::$requests);
    }

    public function testLeFiltreDeLExtensionPeutEcarterUnEMail(): void
    {
        $this->enable();
        add_filter('fastermessage_route_wp_mail', static fn (bool $route, array $atts) => !str_contains((string) $atts['subject'], 'mot de passe'), 10, 2);

        self::assertNull(MailRouter::filter(null, $this->mail()));
        self::assertSame([], FmWp::$requests);
    }

    public function testUnRefusDeLApiRendLaMainAWordPress(): void
    {
        $this->enable();
        $this->respondInSequence([422]);

        self::assertNull(MailRouter::filter(null, $this->mail()), 'une réinitialisation de mot de passe ne doit jamais être perdue');
        self::assertCount(1, FmWp::$requests);
        self::assertSame([], $this->fired('wp_mail_succeeded'));
        self::assertSame([], $this->fired('wp_mail_failed'), 'WordPress signalera lui-même l’issue de son envoi');
    }

    // ── Les e-mails qui partent par FasterMessage ───────────────────────────

    public function testUnTexteBrutPartEnTexteEtEnHtmlEchappe(): void
    {
        $this->enable();
        $this->respondInSequence([201]);

        self::assertTrue(MailRouter::filter(null, $this->mail()));

        $body = $this->bodies()[0];
        self::assertSame('awa@exemple.com', $body['to']);
        self::assertSame('site@maboutique.com', $body['from']);
        self::assertSame('Réinitialisation du mot de passe', $body['content']['subject']);
        self::assertStringContainsString('&lt;https://maboutique.com/wp-login.php?action=rp&amp;key=abc&gt;', $body['content']['html']);
        self::assertStringStartsWith('<p>Bonjour,</p>', $body['content']['html']);
        self::assertStringContainsString('<https://maboutique.com/wp-login.php?action=rp&key=abc>', $body['content']['text']);
        self::assertCount(1, $this->fired('wp_mail_succeeded'));
    }

    public function testUnEnTeteHtmlEnvoieLeCorpsTelQuel(): void
    {
        $this->enable();
        $this->respondInSequence([201]);

        MailRouter::filter(null, $this->mail(['message' => '<h1>Commande 1187</h1>', 'headers' => ['Content-Type: text/html; charset=UTF-8', 'From: Boutique <ventes@autre.com>']]));

        $body = $this->bodies()[0];
        self::assertSame(['subject' => 'Réinitialisation du mot de passe', 'html' => '<h1>Commande 1187</h1>'], $body['content']);
        self::assertSame('site@maboutique.com', $body['from'], 'l’en-tête From est ignoré : seule une adresse validée envoie');
    }

    public function testLeFiltreWpMailContentTypeActiveLeHtml(): void
    {
        $this->enable();
        $this->respondInSequence([201]);
        add_filter('wp_mail_content_type', static fn () => 'text/html');

        MailRouter::filter(null, $this->mail(['message' => '<p>Commande</p>']));

        self::assertSame('<p>Commande</p>', $this->bodies()[0]['content']['html']);
        self::assertArrayNotHasKey('text', $this->bodies()[0]['content']);
    }

    public function testSansAdresseParDefautLApiChoisitLExpediteur(): void
    {
        $this->enable(['email_sender' => '']);
        $this->respondInSequence([201]);

        self::assertTrue(MailRouter::filter(null, $this->mail()));
        self::assertArrayNotHasKey('from', $this->bodies()[0]);
    }

    public function testPlusieursDestinatairesUnEnvoiParAdresse(): void
    {
        $this->enable();
        $this->respondInSequence([201, 201, 201]);

        self::assertTrue(MailRouter::filter(null, $this->mail(['to' => 'Awa Koffi <awa@exemple.com>, kodjo@exemple.com'])));
        self::assertTrue(MailRouter::filter(null, $this->mail(['to' => ['"Sena" <sena@exemple.com>']])));

        self::assertSame(['awa@exemple.com', 'kodjo@exemple.com', 'sena@exemple.com'], array_column($this->bodies(), 'to'));
    }

    public function testLePremierEchoueRienNEstPartiWordPressEnvoieTout(): void
    {
        $this->enable();
        $this->respondInSequence([422, 201]);

        self::assertNull(MailRouter::filter(null, $this->mail(['to' => 'awa@exemple.com, kodjo@exemple.com'])));
        self::assertCount(1, FmWp::$requests, 'on s’arrête au premier échec');
    }

    public function testUnEchecApresUnSuccesNeFaitPasDeDoublon(): void
    {
        $this->enable();
        $this->respondInSequence([201, 422, 201]);

        $result = MailRouter::filter(null, $this->mail(['to' => 'awa@exemple.com, kodjo@exemple.com, sena@exemple.com']));

        self::assertTrue($result, 'null ferait renvoyer par WordPress l’e-mail déjà reçu par Awa');
        self::assertCount(3, FmWp::$requests, 'les destinataires suivants sont tout de même servis');
        self::assertSame(['awa@exemple.com', 'sena@exemple.com'], $this->fired('wp_mail_succeeded')[0][1][0]['to']);
        $failed = $this->fired('wp_mail_failed');
        self::assertCount(1, $failed);
        self::assertSame('wp_mail_failed', $failed[0][1][0]->get_error_code());
        self::assertSame(['kodjo@exemple.com'], $failed[0][1][0]->get_error_data()['to']);
        self::assertSame(['kodjo@exemple.com' => 'invalid_sender'], $failed[0][1][0]->get_error_data()['fastermessage_errors']);
    }

    public function testUnWpMailPendantUnEnvoiPartNormalement(): void
    {
        $this->enable();
        $nested = 'pas appelé';
        add_filter('http_request_args', static function (array $args) use (&$nested) {
            // Une extension qui enverrait un e-mail pendant l'appel à l'API.
            $nested = MailRouter::filter(null, ['to' => 'admin@exemple.com', 'subject' => 'x', 'message' => 'y']);

            return $args;
        });
        $this->respondInSequence([201]);

        self::assertTrue(MailRouter::filter(null, $this->mail()));
        self::assertNull($nested, 'garde anti-réentrance');
        self::assertCount(1, FmWp::$requests);
    }

    #[RunInSeparateProcess]
    public function testLaConstanteImposeLeRoutage(): void
    {
        define('FASTERMESSAGE_ROUTE_WP_MAIL', true);
        $this->enable(['route_wp_mail' => '']);

        self::assertTrue(Settings::isEnabled('route_wp_mail'));
        self::assertTrue(Settings::isLocked('route_wp_mail'));
    }
}
