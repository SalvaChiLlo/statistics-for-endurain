<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

use App\Infrastructure\Logging\Monolog;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Time\Sleep;
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
    private const int DEFAULT_NUM_RECORDS_PER_PAGE = 200;
    // Endurain has no documented rate-limit header contract (unlike Strava's precise
    // 15-minute/daily windows), so we fall back to a generic bounded retry with
    // exponential backoff on HTTP 429. 4 retries (5 attempts total) with a doubling
    // delay starting at 1 second keeps the worst case (1+2+4+8=15s of sleeping) short
    // enough to not stall an import run indefinitely, while still giving a transient
    // rate limit a real chance to clear.
    private const int MAX_RATE_LIMIT_RETRIES = 4;
    private const int BASE_RETRY_DELAY_IN_SECONDS = 1;

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
        private readonly Sleep $sleep,
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

        $numberOfRetries = 0;

        while (true) {
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

                if ($numberOfRetries >= self::MAX_RATE_LIMIT_RETRIES) {
                    throw EndurainRateLimitExceeded::afterRetries($numberOfRetries);
                }

                $this->sleep->sweetDreams(self::BASE_RETRY_DELAY_IN_SECONDS * 2 ** $numberOfRetries);
                ++$numberOfRetries;

                continue;
            }

            $this->logger->info(new Monolog($method, $path));

            return $response->getBody()->getContents();
        }
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
     * Fetches the full activity list for the given user, transparently paginating
     * through Endurain's path-segment based pagination scheme until a page comes
     * back empty or shorter than the requested page size.
     *
     * @return array<mixed>
     */
    public function getActivities(
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $sortBy = null,
        ?string $sortOrder = null,
        int $numRecordsPerPage = self::DEFAULT_NUM_RECORDS_PER_PAGE,
    ): array {
        $activities = [];
        $pageNumber = 1;

        do {
            $page = Json::decode($this->request(
                sprintf('api/v1/activities/user/%d/page_number/%d/num_records/%d', $userId, $pageNumber, $numRecordsPerPage),
                'GET',
                [
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer '.$this->getAccessToken(),
                        self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
                    ],
                    RequestOptions::QUERY => array_filter([
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder,
                    ], fn (?string $value) => !is_null($value)),
                ]
            ));

            $activities = array_merge($activities, $page);
            ++$pageNumber;
            // A page shorter than the requested size means there is nothing left to fetch.
            // This must be a strict count comparison to guarantee the loop terminates.
        } while (count($page) === $numRecordsPerPage);

        return $activities;
    }

    /**
     * @return array<mixed>
     */
    public function getActivity(int $activityId): array
    {
        return Json::decode($this->request('api/v1/activities/'.$activityId, 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
                self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
            ],
        ]));
    }

    /**
     * Returns the numeric user id of the currently authenticated Endurain user, decoded
     * from the 'sub' claim of the (cached) JWT access token. Endurain's API does not expose
     * a dedicated "get my profile" endpoint yet, but the access token's 'sub' claim is
     * confirmed to be the numeric user id.
     */
    public function getCurrentUserId(): int
    {
        $accessToken = $this->getAccessToken();

        $segments = explode('.', $accessToken);
        if (3 !== count($segments)) {
            throw new \RuntimeException('Could not decode Endurain access token: unexpected JWT format');
        }

        $payload = self::base64UrlDecode($segments[1]);
        $decodedPayload = Json::decode($payload);

        if (empty($decodedPayload['sub'])) {
            throw new \RuntimeException('Could not decode Endurain access token: missing "sub" claim');
        }

        return (int) $decodedPayload['sub'];
    }

    private static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if (0 !== $padding) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($data, true) ?: '';
    }

    /**
     * Fetches every stream type that has data for the given activity.
     * Endurain returns one array entry per stream type that has data (types
     * with no data are simply absent from the array, not empty entries).
     *
     * @return array<mixed>
     */
    public function getAllActivityStreams(int $activityId): array
    {
        return Json::decode($this->request('api/v1/activities_streams/activity_id/'.$activityId.'/all', 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
                self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
            ],
        ]));
    }

    /**
     * Fetches the paginated gear list for the authenticated user. Endurain
     * wraps this response in a pagination object (`{"total", "num_records",
     * "page_number", "records"}`), unlike the bare-array activities list;
     * only the "records" array is returned here, matching the "return the
     * useful raw array" convention of the other getters on this class.
     *
     * This is intentionally a single-page fetch: gear collections are
     * expected to be small, and full pagination handling is out of scope
     * for the narrow single-activity gear-linkage use case this supports.
     *
     * @return array<mixed>
     */
    public function getGears(): array
    {
        $decoded = Json::decode($this->request('api/v1/gears', 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
                self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
            ],
        ]));

        return $decoded['records'] ?? [];
    }

    /**
     * Fetches a single gear item by id.
     *
     * @return array<mixed>
     */
    public function getGear(int $gearId): array
    {
        return Json::decode($this->request('api/v1/gears/id/'.$gearId, 'GET', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
                self::CLIENT_TYPE_HEADER => self::CLIENT_TYPE_VALUE,
            ],
        ]));
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
