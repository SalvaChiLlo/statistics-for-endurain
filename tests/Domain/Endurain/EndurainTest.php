<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain;

use App\Domain\Endurain\Endurain;
use App\Domain\Endurain\EndurainPassword;
use App\Domain\Endurain\EndurainUrl;
use App\Domain\Endurain\EndurainUsername;
use App\Infrastructure\Serialization\Json;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EndurainTest extends TestCase
{
    private MockObject $client;
    private MockObject $logger;

    public function testLoginObtainsAndCachesAccessAndRefreshTokens(): void
    {
        $this->logger
            ->expects($this->never())
            ->method('log');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'api/v1/auth/login',
                [
                    'base_uri' => 'https://endurain.example.com',
                    RequestOptions::HEADERS => [
                        'X-Client-Type' => 'mobile',
                    ],
                    RequestOptions::FORM_PARAMS => [
                        'username' => 'theUsername',
                        'password' => 'thePassword',
                    ],
                ]
            )
            ->willReturn(new Response(200, [], Json::encode([
                'session_id' => 'the-session-id',
                'access_token' => 'theAccessToken',
                'refresh_token' => 'theRefreshToken',
                'token_type' => 'bearer',
                'expires_in' => 899,
                'refresh_token_expires_in' => 604799,
            ])));

        $endurain = $this->buildEndurain('2025-11-02 12:00:00');

        $this->assertEquals('theAccessToken', $endurain->getAccessToken());
        // Second call, still within the same instance/clock, must hit the cache and not trigger another login.
        $this->assertEquals('theAccessToken', $endurain->getAccessToken());
    }

    public function testGetAccessTokenWithinLifetimeDoesNotTriggerAnotherLogin(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info');

        $this->client
            ->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], Json::encode([
                'access_token' => 'theAccessToken',
                'refresh_token' => 'theRefreshToken',
                'expires_in' => 899,
            ])));

        $endurainAtLogin = $this->buildEndurain('2025-11-02 12:00:00');
        $this->assertEquals('theAccessToken', $endurainAtLogin->getAccessToken());

        // A new client instance backed by a clock that has advanced, but still within the token's lifetime.
        $endurainLater = $this->buildEndurain('2025-11-02 12:10:00');
        $this->assertEquals('theAccessToken', $endurainLater->getAccessToken());
    }

    public function testGetAccessTokenRefreshesUsingCachedRefreshTokenWhenExpired(): void
    {
        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('api/v1/auth/login', $path);
                    $this->assertEquals([
                        'base_uri' => 'https://endurain.example.com',
                        RequestOptions::HEADERS => [
                            'X-Client-Type' => 'mobile',
                        ],
                        RequestOptions::FORM_PARAMS => [
                            'username' => 'theUsername',
                            'password' => 'thePassword',
                        ],
                    ], $options);

                    return new Response(200, [], Json::encode([
                        'access_token' => 'accessToken1',
                        'refresh_token' => 'refreshToken1',
                        'expires_in' => 899,
                    ]));
                }

                $this->assertEquals('POST', $method);
                $this->assertEquals('api/v1/auth/refresh', $path);
                $this->assertEquals([
                    'base_uri' => 'https://endurain.example.com',
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer refreshToken1',
                        'X-Client-Type' => 'mobile',
                    ],
                ], $options);

                return new Response(200, [], Json::encode([
                    'access_token' => 'accessToken2',
                    'refresh_token' => 'refreshToken2',
                    'expires_in' => 899,
                ]));
            });

        $endurainAtLogin = $this->buildEndurain('2025-11-02 12:00:00');
        $this->assertEquals('accessToken1', $endurainAtLogin->getAccessToken());

        // Clock has advanced beyond the access token's 899s lifetime.
        $endurainAfterExpiry = $this->buildEndurain('2025-11-02 12:20:00');
        $this->assertEquals('accessToken2', $endurainAfterExpiry->getAccessToken());
    }

    public function testRotatedRefreshTokenIsUsedOnTheNextRefreshCycle(): void
    {
        $this->logger
            ->expects($this->exactly(3))
            ->method('info');

        $matcher = $this->exactly(3);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('api/v1/auth/login', $path);

                    return new Response(200, [], Json::encode([
                        'access_token' => 'accessToken1',
                        'refresh_token' => 'refreshToken1',
                        'expires_in' => 899,
                    ]));
                }

                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('api/v1/auth/refresh', $path);
                    $this->assertEquals([
                        'base_uri' => 'https://endurain.example.com',
                        RequestOptions::HEADERS => [
                            'Authorization' => 'Bearer refreshToken1',
                            'X-Client-Type' => 'mobile',
                        ],
                    ], $options);

                    return new Response(200, [], Json::encode([
                        'access_token' => 'accessToken2',
                        'refresh_token' => 'refreshToken2',
                        'expires_in' => 899,
                    ]));
                }

                $this->assertEquals('api/v1/auth/refresh', $path);
                $this->assertEquals([
                    'base_uri' => 'https://endurain.example.com',
                    RequestOptions::HEADERS => [
                        // The rotated refresh token from the previous refresh cycle must be used here, not the original one.
                        'Authorization' => 'Bearer refreshToken2',
                        'X-Client-Type' => 'mobile',
                    ],
                ], $options);

                return new Response(200, [], Json::encode([
                    'access_token' => 'accessToken3',
                    'refresh_token' => 'refreshToken3',
                    'expires_in' => 899,
                ]));
            });

        $endurainAtLogin = $this->buildEndurain('2025-11-02 12:00:00');
        $this->assertEquals('accessToken1', $endurainAtLogin->getAccessToken());

        $endurainAfterFirstExpiry = $this->buildEndurain('2025-11-02 12:20:00');
        $this->assertEquals('accessToken2', $endurainAfterFirstExpiry->getAccessToken());

        $endurainAfterSecondExpiry = $this->buildEndurain('2025-11-02 12:40:00');
        $this->assertEquals('accessToken3', $endurainAfterSecondExpiry->getAccessToken());
    }

    public function testNoCredentialsOrTokensAreEverLogged(): void
    {
        $this->client
            ->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], Json::encode([
                'access_token' => 'theSuperSecretAccessToken',
                'refresh_token' => 'theSuperSecretRefreshToken',
                'expires_in' => 899,
            ])));

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($log): bool {
                $string = (string) $log;
                $this->assertStringNotContainsString('thePassword', $string);
                $this->assertStringNotContainsString('theUsername', $string);
                $this->assertStringNotContainsString('theSuperSecretAccessToken', $string);
                $this->assertStringNotContainsString('theSuperSecretRefreshToken', $string);

                return true;
            }));

        $this->logger
            ->expects($this->never())
            ->method('log');

        $endurain = $this->buildEndurain('2025-11-02 12:00:00');
        $endurain->getAccessToken();
    }

    public function testGetActivitiesLoopsThroughAllPagesAndTerminatesOnShortPage(): void
    {
        $this->logger
            ->expects($this->exactly(4))
            ->method('info');

        $matcher = $this->exactly(4);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('api/v1/auth/login', $path);

                    return new Response(200, [], Json::encode([
                        'access_token' => 'theAccessToken',
                        'refresh_token' => 'theRefreshToken',
                        'expires_in' => 899,
                    ]));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals([
                    'base_uri' => 'https://endurain.example.com',
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer theAccessToken',
                        'X-Client-Type' => 'mobile',
                    ],
                    RequestOptions::QUERY => [],
                ], $options);

                if (2 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('api/v1/activities/user/1/page_number/1/num_records/2', $path);

                    return new Response(200, [], Json::encode([
                        ['id' => 1],
                        ['id' => 2],
                    ]));
                }

                if (3 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('api/v1/activities/user/1/page_number/2/num_records/2', $path);

                    return new Response(200, [], Json::encode([
                        ['id' => 3],
                        ['id' => 4],
                    ]));
                }

                // Third page is short (fewer records than requested), the loop must stop here.
                $this->assertEquals('api/v1/activities/user/1/page_number/3/num_records/2', $path);

                return new Response(200, [], Json::encode([
                    ['id' => 5],
                ]));
            });

        $endurain = $this->buildEndurain('2025-11-02 12:00:00');

        $this->assertEquals([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
            ['id' => 5],
        ], $endurain->getActivities(userId: 1, numRecordsPerPage: 2));
    }

    public function testGetActivitiesTerminatesImmediatelyOnEmptyFirstPageWithoutInfiniteLooping(): void
    {
        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    return new Response(200, [], Json::encode([
                        'access_token' => 'theAccessToken',
                        'refresh_token' => 'theRefreshToken',
                        'expires_in' => 899,
                    ]));
                }

                $this->assertEquals('api/v1/activities/user/1/page_number/1/num_records/2', $path);

                return new Response(200, [], Json::encode([]));
            });

        $endurain = $this->buildEndurain('2025-11-02 12:00:00');

        $this->assertEquals([], $endurain->getActivities(userId: 1, numRecordsPerPage: 2));
    }

    public function testGetActivitiesPassesOptionalFilters(): void
    {
        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    return new Response(200, [], Json::encode([
                        'access_token' => 'theAccessToken',
                        'refresh_token' => 'theRefreshToken',
                        'expires_in' => 899,
                    ]));
                }

                $this->assertEquals('api/v1/activities/user/1/page_number/1/num_records/200', $path);
                $this->assertEquals([
                    'base_uri' => 'https://endurain.example.com',
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer theAccessToken',
                        'X-Client-Type' => 'mobile',
                    ],
                    RequestOptions::QUERY => [
                        'start_date' => '2025-01-01',
                        'end_date' => '2025-02-01',
                        'sort_by' => 'start_time',
                        'sort_order' => 'desc',
                    ],
                ], $options);

                return new Response(200, [], Json::encode([]));
            });

        $endurain = $this->buildEndurain('2025-11-02 12:00:00');

        $endurain->getActivities(
            userId: 1,
            startDate: '2025-01-01',
            endDate: '2025-02-01',
            sortBy: 'start_time',
            sortOrder: 'desc',
        );
    }

    public function testGetActivity(): void
    {
        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $matcher = $this->exactly(2);
        $this->client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, array $options) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('POST', $method);
                    $this->assertEquals('api/v1/auth/login', $path);

                    return new Response(200, [], Json::encode([
                        'access_token' => 'theAccessToken',
                        'refresh_token' => 'theRefreshToken',
                        'expires_in' => 899,
                    ]));
                }

                $this->assertEquals('GET', $method);
                $this->assertEquals('api/v1/activities/3', $path);
                $this->assertEquals([
                    'base_uri' => 'https://endurain.example.com',
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer theAccessToken',
                        'X-Client-Type' => 'mobile',
                    ],
                ], $options);

                return new Response(200, [], Json::encode(['id' => 3, 'name' => 'Workout']));
            });

        $endurain = $this->buildEndurain('2025-11-02 12:00:00');

        $this->assertEquals(
            ['id' => 3, 'name' => 'Workout'],
            $endurain->getActivity(3)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        Endurain::$cachedAccessToken = null;
        Endurain::$cachedRefreshToken = null;
        Endurain::$cachedAccessTokenExpiresOn = null;
    }

    private function buildEndurain(string $dateTime): Endurain
    {
        return new Endurain(
            client: $this->client,
            endurainUrl: EndurainUrl::fromString('https://endurain.example.com'),
            endurainUsername: EndurainUsername::fromString('theUsername'),
            endurainPassword: EndurainPassword::fromString('thePassword'),
            logger: $this->logger,
            clock: PausedClock::fromString($dateTime),
        );
    }
}
