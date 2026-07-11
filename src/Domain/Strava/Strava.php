<?php

namespace App\Domain\Strava;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Gear\GearId;
use App\Domain\Strava\RateLimit\StravaRateLimitHasBeenReached;
use App\Domain\Strava\RateLimit\StravaRateLimits;
use App\Infrastructure\Console\ConsoleOutputAware;
use App\Infrastructure\Logging\Monolog;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Time\Sleep;
use App\Infrastructure\ValueObject\String\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('strava-api')]
class Strava
{
    use ConsoleOutputAware;

    public static ?string $cachedAccessToken = null;
    /** @var array<mixed>|null */
    public static ?array $cachedActivitiesResponse = null;
    private static ?StravaRateLimits $stravaRateLimits = null;

    public function __construct(
        private readonly Client $client,
        #[\SensitiveParameter]
        private readonly StravaClientId $stravaClientId,
        #[\SensitiveParameter]
        private readonly StravaClientSecret $stravaClientSecret,
        #[\SensitiveParameter]
        private readonly StravaRefreshToken $stravaRefreshToken,
        private readonly Sleep $sleep,
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
            'base_uri' => 'https://www.strava.com/',
        ], $options);
        // An application's 15-minute limit is reset at natural 15-minute intervals corresponding to 0, 15, 30 and 45 minutes after the hour.
        $minutesUntilNextFifteenMinuteInterval = (15 - ($this->clock->getCurrentDateTimeImmutable()->getMinutesWithoutLeadingZero() % 15)) + 1;
        $secondsUntilNextFifteenMinuteInterval = $minutesUntilNextFifteenMinuteInterval * 60;

        try {
            $response = $this->client->request($method, $path, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if (429 !== $response?->getStatusCode()) {
                // Rethrow exception if it's not a rate limit error.
                if ($error = $response?->getBody()->getContents()) {
                    throw new RequestException(message: $error, request: $e->getRequest(), response: $e->getResponse());
                }
                throw $e;
            }

            if (!($stravaRateLimits = StravaRateLimits::fromResponse($response)) instanceof StravaRateLimits) {
                // No info about rate limits available, rethrow exception.
                throw $e;
            }

            if ($stravaRateLimits->dailyReadRateLimitHasBeenReached()) {
                throw StravaRateLimitHasBeenReached::dailyReadLimit();
            }

            if ($stravaRateLimits->fifteenMinReadRateLimitHasBeenReached()) {
                throw StravaRateLimitHasBeenReached::fifteenMinuteReadLimit($minutesUntilNextFifteenMinuteInterval);
            }

            throw $e;
        }

        $this->logger->info(new Monolog(
            $method,
            $path,
            'x-ratelimit-limit: '.$response->getHeaderLine('x-ratelimit-limit'),
            'x-ratelimit-usage: '.$response->getHeaderLine('x-ratelimit-usage'),
            'x-readratelimit-limit: '.$response->getHeaderLine('x-readratelimit-limit'),
            'x-readratelimit-usage: '.$response->getHeaderLine('x-readratelimit-usage'),
        ));

        if (($stravaRateLimits = StravaRateLimits::fromResponse($response)) instanceof StravaRateLimits) {
            self::$stravaRateLimits = $stravaRateLimits;
            if ($stravaRateLimits->fifteenMinReadRateLimitHasBeenReached()) {
                // The next request will hit the 15-minute rate limit. Pause and make sure the import does not crash.
                $this->getConsoleOutput()->writeln(sprintf(
                    '<comment>Whoa there! We are about to hit Strava’s 15-minute API rate limit. Taking a short %s-minute breather before getting back on track. Please be patient</comment>',
                    $minutesUntilNextFifteenMinuteInterval
                ));
                $this->sleep->sweetDreams($secondsUntilNextFifteenMinuteInterval);
            }
        }

        return $response->getBody()->getContents();
    }

    public function getRateLimit(): ?StravaRateLimits
    {
        return self::$stravaRateLimits;
    }

    public function verifyAccessToken(): void
    {
        try {
            $accessToken = $this->getAccessToken();
        } catch (ClientException|RequestException $e) {
            throw new InvalidStravaAccessToken(message: $e->getMessage(), code: $e->getCode(), previous: $e);
        }

        try {
            // Check if the access token has the required scopes.
            $this->client->request(
                'GET',
                'api/v3/athlete/activities',
                [
                    'base_uri' => 'https://www.strava.com/',
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer '.$accessToken,
                    ],
                    RequestOptions::QUERY => [
                        'per_page' => 1,
                    ],
                ]
            );
        } catch (ClientException|RequestException $e) {
            if (401 === $e->getResponse()?->getStatusCode()) {
                throw new InsufficientStravaAccessTokenScopes();
            }

            throw $e;
        }
    }

    public function getAccessToken(): string
    {
        if (!is_null(Strava::$cachedAccessToken)) {
            return Strava::$cachedAccessToken;
        }

        $response = $this->request('oauth/token', 'POST', [
            RequestOptions::FORM_PARAMS => [
                'client_id' => (string) $this->stravaClientId,
                'client_secret' => (string) $this->stravaClientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $this->stravaRefreshToken,
            ],
        ]);

        $decodedResponse = Json::decode($response);
        if (empty($decodedResponse['access_token'])) {
            throw new \RuntimeException('Could not fetch Strava accessToken');
        }

        Strava::$cachedAccessToken = $decodedResponse['access_token'];

        return $decodedResponse['access_token'];
    }

    /**
     * @return array<mixed>
     */
    public function getAthlete(): array
    {
        return Json::decode($this->request('api/v3/athlete', 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
        ]));
    }

    /**
     * @return array<mixed>
     */
    public function getActivities(): array
    {
        if (!is_null(Strava::$cachedActivitiesResponse)) {
            return Strava::$cachedActivitiesResponse;
        }

        Strava::$cachedActivitiesResponse = [];

        $page = 1;
        do {
            $activities = Json::decode($this->request('api/v3/athlete/activities', 'GET', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->getAccessToken(),
                ],
                RequestOptions::QUERY => [
                    'page' => $page,
                    'per_page' => 200,
                ],
            ]));

            Strava::$cachedActivitiesResponse = array_merge(
                Strava::$cachedActivitiesResponse,
                $activities
            );
            ++$page;
        } while (count($activities) > 0);

        return Strava::$cachedActivitiesResponse;
    }

    /**
     * @return array<mixed>
     */
    public function getActivity(ActivityId $activityId): array
    {
        return Json::decode($this->request('api/v3/activities/'.$activityId->toUnprefixedString(), 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
        ]));
    }

    /**
     * @return array<mixed>
     */
    public function getActivityZones(ActivityId $activityId): array
    {
        return Json::decode($this->request('api/v3/activities/'.$activityId->toUnprefixedString().'/zones', 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
        ]));
    }

    /**
     * @return array<mixed>
     */
    public function getAllActivityStreams(ActivityId $activityId): array
    {
        return Json::decode($this->request('api/v3/activities/'.$activityId->toUnprefixedString().'/streams', 'GET', [
            RequestOptions::QUERY => [
                'keys' => implode(',', array_map(fn (StreamType $streamType) => $streamType->value, StreamType::cases())),
            ],
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
        ]));
    }

    /**
     * @return array<mixed>
     */
    public function getActivityPhotos(ActivityId $activityId): array
    {
        return Json::decode($this->request('api/v3/activities/'.$activityId->toUnprefixedString().'/photos', 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
            RequestOptions::QUERY => [
                'size' => 5000,
            ],
        ]));
    }

    /**
     * @return array<mixed>
     */
    public function getGear(GearId $gearId): array
    {
        return Json::decode($this->request('api/v3/gear/'.$gearId->toUnprefixedString(), 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
            ],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookSubscription(): array
    {
        return Json::decode($this->request('api/v3/push_subscriptions', 'GET', [
            RequestOptions::QUERY => [
                'client_id' => (string) $this->stravaClientId,
                'client_secret' => (string) $this->stravaClientSecret,
            ],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function createWebhookSubscription(Url $callbackUrl, string $verifyToken): array
    {
        return Json::decode($this->request('api/v3/push_subscriptions', 'POST', [
            RequestOptions::FORM_PARAMS => [
                'client_id' => (string) $this->stravaClientId,
                'client_secret' => (string) $this->stravaClientSecret,
                'callback_url' => (string) $callbackUrl,
                'verify_token' => $verifyToken,
            ],
        ]));
    }

    public function deleteWebhookSubscription(string $subscriptionId): void
    {
        $this->request('api/v3/push_subscriptions/'.$subscriptionId, 'DELETE', [
            RequestOptions::QUERY => [
                'client_id' => (string) $this->stravaClientId,
                'client_secret' => (string) $this->stravaClientSecret,
            ],
        ]);
    }

    public function downloadImage(string $uri): string
    {
        $response = $this->client->request('GET', $uri, [
            RequestOptions::DECODE_CONTENT => false,
        ]);

        return $response->getBody()->getContents();
    }
}
