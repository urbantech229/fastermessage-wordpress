<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk;

use FasterMessageWP\Sdk\Http\CurlTransport;
use FasterMessageWP\Sdk\Http\Requester;
use FasterMessageWP\Sdk\Http\Transport;
use FasterMessageWP\Sdk\Resource\Account;
use FasterMessageWP\Sdk\Resource\ContactGroups;
use FasterMessageWP\Sdk\Resource\Contacts;
use FasterMessageWP\Sdk\Resource\Email;
use FasterMessageWP\Sdk\Resource\Messages;
use FasterMessageWP\Sdk\Resource\Otp;
use FasterMessageWP\Sdk\Resource\Sms;
use FasterMessageWP\Sdk\Resource\Whatsapp;

/** Point d'entrée du SDK. */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.fastermessage.com';

    public readonly Sms $sms;
    public readonly Email $email;
    public readonly Whatsapp $whatsapp;
    public readonly Messages $messages;
    public readonly Otp $otp;
    public readonly Contacts $contacts;
    public readonly ContactGroups $contactGroups;
    public readonly Account $account;

    private readonly bool $testMode;

    /**
     * @param string $username identifiant d'API, compte débité
     * @param string $password mot de passe d'API
     * @param string $apiKey   clé `fm_live_…` (réel) ou `fm_test_…` (simulé)
     * @param array{base_url?: string, timeout?: float, max_retries?: int, transport?: Transport, sleep?: callable(float): void} $options
     *        `base_url` : le domaine d'API de votre marque, le cas échéant
     */
    public function __construct(string $username, string $password, string $apiKey, array $options = [])
    {
        if ($username === '' || $password === '' || $apiKey === '') {
            throw new \InvalidArgumentException('L\'identifiant, le mot de passe et la clé d\'API sont tous obligatoires.');
        }

        if (preg_match('/^[\x21-\x7E]+$/D', $apiKey) !== 1) {
            // Le message ne cite jamais la valeur : il finirait dans vos journaux.
            throw new \InvalidArgumentException('La clé d\'API contient des caractères invalides : vérifiez son copier-coller.');
        }

        $this->testMode = str_contains($apiKey, '_test_');

        $http = new Requester(
            'Basic ' . base64_encode($username . ':' . $password),
            $apiKey,
            self::checkedBaseUrl($options['base_url'] ?? self::DEFAULT_BASE_URL),
            $options['timeout'] ?? 30.0,
            max(0, $options['max_retries'] ?? 2),
            $options['transport'] ?? new CurlTransport(),
            $options['sleep'] ?? null,
        );

        $this->sms = new Sms($http);
        $this->email = new Email($http);
        $this->whatsapp = new Whatsapp($http);
        $this->messages = new Messages($http);
        $this->otp = new Otp($http);
        $this->contacts = new Contacts($http);
        $this->contactGroups = new ContactGroups($http);
        $this->account = new Account($http);
    }

    /** Les identifiants ne partent qu'en https, sauf vers la machine locale. */
    private static function checkedBaseUrl(string $value): string
    {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $allowed = $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));

        if (!$allowed || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('base_url doit être une adresse https, sans identifiants (http seulement vers la machine locale).');
        }

        return rtrim($value, '/');
    }

    /** Vrai avec une clé de test : messages et OTP simulés, mais contacts et soldes réels. */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /** Évite d'écrire les identifiants dans un journal par `var_dump` ou `print_r`. */
    public function __debugInfo(): array
    {
        return ['testMode' => $this->testMode];
    }
}
