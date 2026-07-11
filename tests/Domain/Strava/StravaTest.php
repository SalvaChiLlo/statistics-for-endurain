<?php

namespace App\Tests\Domain\Strava;

use App\Domain\Activity\ActivityId;
use App\Domain\Gear\GearId;
use App\Domain\Strava\InsufficientStravaAccessTokenScopes;
use App\Domain\Strava\InvalidStravaAccessToken;
use App\Domain\Strava\RateLimit\StravaRateLimitHasBeenReached;
use App\Domain\Strava\Strava;
use App\Domain\Strava\StravaClientId;
use App\Domain\Strava\StravaClientSecret;
use App\Domain\Strava\StravaRefreshToken;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\String\Url;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\Infrastructure\Time\Sleep\NullSleep;
use App\Tests\SpyOutput;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Spatie\Snapshots\MatchesSnapshots;

class StravaTest extends TestCase
{
    use MatchesSnapshots;

    private Strava $strava;

    private MockObject $client;
    private NullSleep $sleep;
    private MockObject $logger;

    public function testGetAccessToken(): void
    {
        $this->logger
            ->expects($this->never())
            ->method('log');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'oauth/token',
            )
        ->willReturn(new Response(200, [], Json::encode(['access_token' => 'theAccessToken'])));

        $this->strava->getAccessToken();
        $this->strava->getAccessToken();
    }

    public function testVerifyAccessToken(): void
    {
        $this->logger
            ->expects($this->never())
            ->method('log');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/athlete/activities', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->strava->verifyAccessToken();
    }

    public function testVerifyAccessTokenWhenTheTokenIsInvalid(): void
    {
        $this->logger
            ->expects($this->never())
            ->method('log');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->willThrowException(RequestException::wrapException(
                new Request('GET', 'uri'),
                new \RuntimeException()
            ));

        $this->expectExceptionObject(new InvalidStravaAccessToken());

        $this->strava->verifyAccessToken();
    }

    public function testVerifyAccessTokenWhenTheTokenHasInsufficientScopes(): void
    {
        $this->logger
            ->expects($this->never())
            ->method('log');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/athlete/activities', $path);

                throw new RequestException(message: 'The error', request: new Request('GET', 'uri'), response: new Response(401, [], Json::encode(['error' => 'The error'])));
            });

        $this->expectExceptionObject(new InsufficientStravaAccessTokenScopes());

        $this->strava->verifyAccessToken();
    }

    public function testVerifyAccessTokenWhenARandomErrorIsThrown(): void
    {
        $this->logger
            ->expects($this->never())
            ->method('log');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/athlete/activities', $path);

                throw new \RuntimeException('Oh no');
            });

        $this->expectExceptionObject(new \RuntimeException('Oh no'));

        $this->strava->verifyAccessToken();
    }

    public function testGetWhenUnexpectedError(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The error');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                throw new RequestException(message: 'The error', request: new Request('GET', 'uri'), response: new Response(500, [], Json::encode(['error' => 'The error'])));
            });

        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->strava->getAthlete();
    }

    public function testGetWhenUnexpectedErrorWithoutBody(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The error');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                throw new RequestException(message: 'The error', request: new Request('GET', 'uri'));
            });

        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->strava->getAthlete();
    }

    public function testGetWhenTooManyRequestsButNoRateLimits(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The error');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                throw new RequestException(message: 'The error', request: new Request('GET', 'uri'), response: new Response(429, [], Json::encode(['error' => 'The error'])));
            });

        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->strava->getAthlete();
    }

    public function testGetWhenTooManyRequestsDailyRateLimitExceeded(): void
    {
        $this->expectExceptionObject(StravaRateLimitHasBeenReached::dailyReadLimit());

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                throw new RequestException(message: 'The error', request: new Request('GET', 'uri'), response: new Response(429, ['x-ratelimit-limit' => '200,2000', 'x-ratelimit-usage' => '1,2', 'x-readratelimit-limit' => '100,1000', 'x-readratelimit-usage' => '99,1001'], Json::encode(['error' => 'The error'])));
            });

        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->strava->getAthlete();
    }

    public function testGetWhenTooManyRequestsFifteenMinuteRateLimitExceeded(): void
    {
        $this->expectExceptionObject(StravaRateLimitHasBeenReached::fifteenMinuteReadLimit(3));

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                throw new RequestException(message: 'The error', request: new Request('GET', 'uri'), response: new Response(429, ['x-ratelimit-limit' => '200,2000', 'x-ratelimit-usage' => '1,2', 'x-readratelimit-limit' => '100,1000', 'x-readratelimit-usage' => '101,998'], Json::encode(['error' => 'The error'])));
            });

        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->strava->getAthlete();
    }

    public function testGetFifteenRateLimitIsAboutToBeHit(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                return new Response(200, [
                    'x-ratelimit-limit' => '200,2000',
                    'x-ratelimit-usage' => '1,2',
                    'x-readratelimit-limit' => '100,1000',
                    'x-readratelimit-usage' => '99,98',
                ], Json::encode(['weight' => 68, 'id' => 10]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $spyOutput = new SpyOutput();
        $this->strava->setConsoleOutput($spyOutput);
        $this->strava->getAthlete();

        $this->assertMatchesTextSnapshot((string) $spyOutput);

        $this->assertEquals(
            180,
            $this->sleep->getTotalSleptInSeconds(),
        );
    }

    public function testGetAthlete(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/athlete', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [
                    'x-ratelimit-limit' => '200,2000',
                    'x-ratelimit-usage' => '1,2',
                    'x-readratelimit-limit' => '100,1000',
                    'x-readratelimit-usage' => '0,0',
                ], Json::encode(['weight' => 68, 'id' => 10]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getAthlete();
        $this->assertMatchesObjectSnapshot($this->strava->getRateLimit());
        $this->assertEquals(
            0,
            $this->sleep->getTotalSleptInSeconds(),
        );
    }

    public function testGetActivities(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/athlete/activities', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getActivities();
        // Test static cache.
        $this->strava->getActivities();
    }

    public function testGetActivity(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/activities/3', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getActivity(ActivityId::fromUnprefixed(3));
    }

    public function testGetActivityZones(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/activities/3/zones', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getActivityZones(ActivityId::fromUnprefixed(3));
    }

    public function testGetAllActivityStreams(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/activities/3/streams', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getAllActivityStreams(ActivityId::fromUnprefixed(3));
    }

    public function testGetAllActivityPhotos(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/activities/3/photos', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getActivityPhotos(ActivityId::fromUnprefixed(3));
    }

    public function testGetGear(): void
    {
        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('oauth/token', $path);
                    $this->assertMatchesJsonSnapshot($options);

                    return new Response(200, [], Json::encode(['access_token' => 'theAccessToken']));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v3/gear/3', $path);
                $this->assertMatchesJsonSnapshot($options);

                return new Response(200, [], Json::encode([]));
            });

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->strava->getGear(GearId::fromUnprefixed(3));
    }

    public function testGetWebhookSubscription(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'api/v3/push_subscriptions',
                [
                    'base_uri' => 'https://www.strava.com/',
                    RequestOptions::QUERY => [
                        'client_id' => 'clientId',
                        'client_secret' => 'clientSecret',
                    ],
                ]
            )
            ->willReturn(new Response(200, [], Json::encode(['id' => 12345])));

        $this->strava->getWebhookSubscription();
    }

    public function testCreateWebhookSubscription(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'api/v3/push_subscriptions',
                [
                    'base_uri' => 'https://www.strava.com/',
                    RequestOptions::FORM_PARAMS => [
                        'client_id' => 'clientId',
                        'client_secret' => 'clientSecret',
                        'callback_url' => 'https://example.com/',
                        'verify_token' => 'the-token',
                    ],
                ]
            )
            ->willReturn(new Response(200, [], Json::encode(['id' => 12345])));

        $this->strava->createWebhookSubscription(
            callbackUrl: Url::fromString('https://example.com/'),
            verifyToken: 'the-token',
        );
    }

    public function testDeleteWebhookSubscription(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with(
                'DELETE',
                'api/v3/push_subscriptions/the-id',
                [
                    'base_uri' => 'https://www.strava.com/',
                    RequestOptions::QUERY => [
                        'client_id' => 'clientId',
                        'client_secret' => 'clientSecret',
                    ],
                ]
            )
            ->willReturn(new Response(200, [], Json::encode(['id' => 12345])));

        $this->strava->deleteWebhookSubscription('the-id');
    }

    public function testDownloadImage(): void
    {
        $this->client
            ->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options): Response {
                $this->assertEquals('GET', $method);
                $this->assertEquals('uri', $path);

                return new Response(200, [], '');
            });

        $this->logger
            ->expects($this->never())
            ->method('info');

        $this->strava->downloadImage('uri');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->strava = new Strava(
            client: $this->client,
            stravaClientId: StravaClientId::fromString('clientId'),
            stravaClientSecret: StravaClientSecret::fromString('clientSecret'),
            stravaRefreshToken: StravaRefreshToken::fromString('refreshToken'),
            sleep: $this->sleep = new NullSleep(),
            logger: $this->logger,
            clock: PausedClock::fromString('2025-11-02 12:43:20')
        );
        $this->strava::$cachedAccessToken = null;
        $this->strava::$cachedActivitiesResponse = null;
    }
}
