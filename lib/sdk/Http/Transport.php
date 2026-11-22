<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Http;

use FasterMessageWP\Sdk\Exception\ConnectionException;

/** Envoi HTTP. Remplaçable : pour un autre client HTTP, ou pour les tests. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException si aucune réponse n'a été obtenue
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
