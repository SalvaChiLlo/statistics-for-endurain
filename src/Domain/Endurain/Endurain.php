<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

use App\Infrastructure\Logging\Monolog;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('endurain-api')]
class Endurain
{
    private const string CLIENT_TYPE_HEADER = 'X-Client-Type';
    private const string CLIENT_TYPE_VALUE = 'mobile';

    public static ?string $cachedAccessToken = null;
    public static ?string $cachedRefreshToken = null;
    public static ?SerializableDateTime $cachedAccessTokenExpiresOn = null;

    public function __construct(
        private readonly Client $client,
        private readonly EndurainUrl $endurainUrl,
        #[\SensitiveParameter]
        private readonly EndurainUsername $endurainUsername,
        #[\SensitiveParameter]
        private readonly EndurainPassword $endurainPassword,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<mixed> $options
     */
    private function request(
        string $path,
        string $method = 'GET',
        array $options = []): string
    {
        $options = array_merge([
            'base_uri' => (string) $this->endurainUrl,
        ], $options);

        try {
            $response = $this->client->request($method, $path, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($error = $response?->getBody()->getContents()) {
                throw new RequestException(message: $error, request: $e->getRequest(), response: $e->getResponse());
            }
            throw $e;
        }

        $this->logger->info(new Monolog($method, $path));

        return $response->getBody()->getContents();
    }

    /**
     * Returns a valid access token, transparently logging in or refreshing the
     * cached token when it does not exist yet or has expired.
     */
    public function getAccessToken(): string
    {
        if (is_null(self::$cachedAccessToken) || is_null(self::$cachedAccessTokenExpiresOn)) {
            return $this->login();
        }

        if ($this->clock->getCurrentDateTimeImmutable()->isAfterOrOn(self::$cachedAccessTokenExpiresOn)) {
            return $this->refresh();
        }

        return self::$cachedAccessToken;
    }

    private function login(): string
    {
        $response = $this->request('api/v1/auth/login', 'POST', [
            RequestOptions::HEADERS => [
                self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
            ],
            RequestOptions::FORM_PARAMS => [
                'username' => (string) $this->endurainUsername,
                'password' => (string) $this->endurainPassword,
            ],
        ]);

        return $this->cacheTokens(Json::decode($response));
    }

    private function refresh(): string
    {
        if (is_null(self::$cachedRefreshToken)) {
            // No refresh token available, fall back to a fresh login.
            return $this->login();
        }

        $response = $this->request('api/v1/auth/refresh', 'POST', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.self::$cachedRefreshToken,
                self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
            ],
        ]);

        return $this->cacheTokens(Json::decode($response));
    }

    /**
     * @param array<mixed> $decodedResponse
     */
    private function cacheTokens(array $decodedResponse): string
    {
        if (empty($decodedResponse['access_token']) || empty($decodedResponse['refresh_token']) || empty($decodedResponse['expires_in'])) {
            throw new \RuntimeException('Could not fetch Endurain access token');
        }

        self::$cachedAccessToken = $decodedResponse['access_token'];
        // Endurain rotates the refresh token on every login/refresh call, the new one must be persisted.
        self::$cachedRefreshToken = $decodedResponse['refresh_token'];
        self::$cachedAccessTokenExpiresOn = $this->clock->getCurrentDateTimeImmutable()->modify(
            sprintf('+%d seconds', (int) $decodedResponse['expires_in'])
        );

        return self::$cachedAccessToken;
    }
}
