<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Http;

use FasterMessageWP\Sdk\Exception\ApiException;
use FasterMessageWP\Sdk\Exception\AuthenticationException;
use FasterMessageWP\Sdk\Exception\ConflictException;
use FasterMessageWP\Sdk\Exception\ConnectionException;
use FasterMessageWP\Sdk\Exception\InsufficientBalanceException;
use FasterMessageWP\Sdk\Exception\InvalidRequestException;
use FasterMessageWP\Sdk\Exception\NotFoundException;
use FasterMessageWP\Sdk\Exception\NotImplementedException;
use FasterMessageWP\Sdk\Exception\PermissionException;
use FasterMessageWP\Sdk\Exception\RateLimitException;
use FasterMessageWP\Sdk\Exception\ServerException;

/**
 * Le chemin unique de tous les appels : en-têtes, idempotence, reprises, erreurs.
 *
 * @internal
 */
final class Requester
{
    public const VERSION = '0.3.0';

    /** Au-delà, le SDK ne bloque pas votre programme : il rend la main avec RateLimitException. */
    private const MAX_RETRY_AFTER = 60;

    /** Un même appel rejoué garde sa clé : c'est ce qui rend une reprise sans risque. */
    private const MUTATING = ['POST', 'PATCH', 'PUT', 'DELETE'];

    /** @var callable(float): void */
    private $sleep;

    /** @param null|callable(float): void $sleep */
    public function __construct(
        private readonly string $authorization,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly Transport $transport,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * @param array<string, mixed>      $query
     * @param null|array<string, mixed> $body
     * @param array{idempotency_key?: string, request_id?: string} $options
     *
     * @return null|array<string, mixed> ressource décodée, `null` sur un 204
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): ?array
    {
        foreach (['idempotency_key', 'request_id'] as $name) {
            // Un retour à la ligne ajouterait un en-tête à la requête.
            if (isset($options[$name]) && preg_match('/[^\x20-\x7E]/', $options[$name]) === 1) {
                throw new \InvalidArgumentException($name . ' contient des caractères invalides.');
            }
        }

        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn ($value) => $value !== null);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization' => $this->authorization,
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'fastermessage-php/' . self::VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if (isset($options['request_id'])) {
            $headers['X-Request-Id'] = $options['request_id'];
        }

        $idempotencyKey = null;
        if (in_array($method, self::MUTATING, true)) {
            $idempotencyKey = $options['idempotency_key'] ?? 'sdk-' . bin2hex(random_bytes(16));
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
            } catch (ConnectionException $error) {
                if ($attempt >= $this->maxRetries) {
                    $error->idempotencyKey = $idempotencyKey;
                    throw $error;
                }
                ($this->sleep)($this->backoff($attempt));
                continue;
            }

            if ($response->status < 300) {
                return $this->decode($response);
            }

            $failure = $this->toException($response);
            $wait = $this->retryDelay($failure, $attempt);
            if ($wait === null || $attempt >= $this->maxRetries) {
                throw $failure;
            }
            ($this->sleep)($wait);
        }
    }

    /**
     * Reprise seulement si la même requête peut réussir plus tard. Un 429 sans
     * `Retry-After` est le plafond OTP par numéro, à l'échelle de l'heure : le rejouer
     * ne servirait à rien. Le 501 dit une fonction non servie, jamais une panne.
     */
    private function retryDelay(ApiException $failure, int $attempt): ?float
    {
        if ($failure->httpStatus === 429) {
            return $failure->retryAfter === null || $failure->retryAfter > self::MAX_RETRY_AFTER ? null : (float) $failure->retryAfter;
        }
        if ($failure->errorCode === 'idempotency_key_in_flight') {
            return $this->backoff($attempt);
        }
        if ($failure->httpStatus >= 500 && $failure->httpStatus !== 501) {
            return $this->backoff($attempt);
        }

        return null;
    }

    private function backoff(int $attempt): float
    {
        return min(8.0, 0.5 * (2 ** $attempt)) * (1 + mt_rand(0, 250) / 1000);
    }

    /** @return null|array<string, mixed> */
    private function decode(Response $response): ?array
    {
        if ($response->status === 204 || trim($response->body) === '') {
            return null;
        }

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new ServerException('Réponse illisible de l\'API.', $response->status, 'invalid_response', 'api_error', null, $response->header('x-request-id'));
        }

        return $data;
    }

    private function toException(Response $response): ApiException
    {
        $decoded = json_decode($response->body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $status = $response->status;
        $retryAfter = $response->header('retry-after');

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 402 => InsufficientBalanceException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status === 501 => NotImplementedException::class,
            $status >= 500 => ServerException::class,
            $status === 400 => InvalidRequestException::class,
            default => ApiException::class,
        };

        return new $class(
            is_string($error['message'] ?? null) ? $error['message'] : 'Erreur HTTP ' . $status . '.',
            $status,
            is_string($error['code'] ?? null) ? $error['code'] : 'http_' . $status,
            is_string($error['type'] ?? null) ? $error['type'] : '',
            is_string($error['param'] ?? null) ? $error['param'] : null,
            is_string($error['request_id'] ?? null) ? $error['request_id'] : $response->header('x-request-id'),
            is_string($retryAfter) && ctype_digit(trim($retryAfter)) ? (int) trim($retryAfter) : null,
        );
    }
}
