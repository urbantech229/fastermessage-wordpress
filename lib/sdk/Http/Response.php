<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Http;

/** Réponse HTTP brute. Les noms d'en-têtes sont en minuscules. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
