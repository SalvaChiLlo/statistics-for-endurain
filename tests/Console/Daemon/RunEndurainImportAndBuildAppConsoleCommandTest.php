<?php

declare(strict_types=1);

namespace App\Tests\Console\Daemon;

use App\Application\AppStatusChecker;
use App\Application\Import\CalculateActivityMetrics\CalculateActivityMetrics;
use App\Application\Import\EndurainImport\DetectEndurainActivityChanges\DetectEndurainActivityChanges;
use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivity;
use App\Console\Daemon\RunEndurainImportAndBuildAppConsoleCommand;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityIds;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Endurain\Endurain;
use App\Domain\Endurain\EndurainPassword;
use App\Domain\Endurain\EndurainUrl;
use App\Domain\Endurain\EndurainUsername;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Serialization\Json;
use App\Tests\Console\ConsoleCommandTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Infrastructure\CQRS\Command\Bus\SpyCommandBus;
use App\Tests\Infrastructure\FileSystem\SuccessfulPermissionChecker;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\Infrastructure\Time\Sleep\NullSleep;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunEndurainImportAndBuildAppConsoleCommandTest extends ConsoleCommandTestCase
{
    use MatchesSnapshots;

    private const string TODAY = '2025-12-04';

    private RunEndurainImportAndBuildAppConsoleCommand $command;
    private SpyCommandBus $commandBus;
    private MockObject $endurain;
    private DetectEndurainActivityChanges $detectEndurainActivityChanges;
    private MockObject $activityRepository;
    private MockObject $activityIdRepository;

    public function testRunImportsNewActivitiesMarksDeletionsAndBuilds(): void
    {
        $this->endurain
            ->expects($this->once())
            ->method('getCurrentUserId')
            ->willReturn(1);

        $this->endurain
            ->expects($this->once())
            ->method('getActivities')
            ->with(userId: 1)
            ->willReturn([['id' => 1], ['id' => 2]]);

        // endurain-2 is already imported locally and still present remotely (untouched),
        // endurain-99 is imported locally but no longer present remotely (delete candidate),
        // endurain-1 is new remotely and must be imported.
        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-2'),
                ActivityId::fromUnprefixed('endurain-99'),
            ]));

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivitiesForDeletion')
            ->with(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-99')]));

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertMatchesJsonSnapshot(Json::encode($this->commandBus->getDispatchedCommands()));
    }

    public function testImportsNothingWhenDiffHasNoNewActivities(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([['id' => 1]]);

        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());

        $dispatchedCommandClasses = array_map(
            static fn (object $command): string => $command::class,
            $this->commandBus->getDispatchedCommands()
        );
        $this->assertNotContains(ImportEndurainActivity::class, $dispatchedCommandClasses);
        $this->assertContains(CalculateActivityMetrics::class, $dispatchedCommandClasses);
    }

    public function testRunningTwiceWithNoRemoteChangesIsANoOpForImports(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([['id' => 1]]);

        // Activity 1 is already locally imported both times, so the diff is empty on every run.
        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);

        $firstRun = new CommandTester($command);
        $firstRun->execute(['command' => $command->getName()]);
        $this->assertSame(Command::SUCCESS, $firstRun->getStatusCode());
        // SpyCommandBus::getDispatchedCommands() drains its internal buffer, so this
        // captures only the commands dispatched by the first run.
        $firstDispatched = array_map(static fn (object $c): string => $c::class, $this->commandBus->getDispatchedCommands());

        $secondRun = new CommandTester($command);
        $secondRun->execute(['command' => $command->getName()]);
        $this->assertSame(Command::SUCCESS, $secondRun->getStatusCode());
        // Isolated to the second run only, for the same reason as above.
        $secondDispatched = array_map(static fn (object $c): string => $c::class, $this->commandBus->getDispatchedCommands());

        $this->assertNotContains(ImportEndurainActivity::class, $firstDispatched);
        $this->assertNotContains(ImportEndurainActivity::class, $secondDispatched);

        // The diff being empty must not stop the rest of the pipeline from running on
        // either invocation: metrics recalculation and a dashboard rebuild still happen
        // every run, true idempotency here means "no duplicate imports/deletions", not
        // "nothing happens at all".
        $this->assertContains(CalculateActivityMetrics::class, $firstDispatched);
        $this->assertContains(CalculateActivityMetrics::class, $secondDispatched);

        // The mutex must be fully released after each run so a second, unrelated
        // invocation is never blocked by a stale lock left behind by an idle no-op run.
        $row = $this->getConnection()->fetchOne(
            'SELECT `value` FROM KeyValue WHERE `key` = :key',
            ['key' => 'lock.importDataOrBuildApp']
        );
        $this->assertFalse($row);
    }

    public function testAbortsAndReleasesMutexWhenAllLocalActivitiesWouldBeMarkedForDeletion(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        // Nothing remains remotely, so every locally imported Endurain activity becomes a
        // deletion candidate - this must be treated as a probable configuration issue.
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([]);

        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $application = new Application();
        $application->addCommand($this->command);
        $commandTester = new CommandTester($application->find(RunEndurainImportAndBuildAppConsoleCommand::NAME));

        try {
            $commandTester->execute(['command' => RunEndurainImportAndBuildAppConsoleCommand::NAME]);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'All activities appear to be marked for deletion. This seems like a configuration issue. Aborting to prevent data loss',
                $e->getMessage()
            );
        }

        // The mutex must have been released on the guard-triggered abort path, otherwise a
        // subsequent run would postpone forever.
        $row = $this->getConnection()->fetchOne(
            'SELECT `value` FROM KeyValue WHERE `key` = :key',
            ['key' => 'lock.importDataOrBuildApp']
        );
        $this->assertFalse($row);
    }

    public function testDoesNotAbortWhenSomeButNotAllActivitiesAreMarkedForDeletion(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([['id' => 2]]);

        // endurain-1 is gone remotely (delete candidate), endurain-2 is still present:
        // not everything is being deleted, so the guard must not trigger.
        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivitiesForDeletion')
            ->with(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCompletesSuccessfullyWhenGetActivitiesHitsA429MidSyncAndRetriesSucceed(): void
    {
        // A real Endurain instance (not the usual mock) backed by a mocked Guzzle client,
        // so the full sync loop genuinely exercises Endurain::request()'s built-in 429
        // retry-with-backoff (from #13) rather than merely trusting it works in isolation.
        $sleep = new NullSleep();
        $client = $this->createMock(Client::class);

        $matcher = $this->exactly(3);
        $client
            ->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path) use ($matcher): Response {
                if (1 === $matcher->numberOfInvocations()) {
                    $this->assertEquals('api/v1/auth/login', $path);

                    return new Response(200, [], Json::encode([
                        'access_token' => $this->buildFakeJwt(['sub' => 1]),
                        'refresh_token' => 'theRefreshToken',
                        'expires_in' => 899,
                    ]));
                }

                if (2 === $matcher->numberOfInvocations()) {
                    // The remote is momentarily rate-limiting the activities-list call.
                    throw new RequestException(message: 'Too Many Requests', request: new Request('GET', $path), response: new Response(429, [], Json::encode(['error' => 'Too Many Requests'])));
                }

                // Retry succeeds: a single, short page ends pagination immediately.
                return new Response(200, [], Json::encode([['id' => 1]]));
            });

        $realEndurain = new Endurain(
            client: $client,
            endurainUrl: EndurainUrl::fromString('https://endurain.example.com'),
            endurainUsername: EndurainUsername::fromString('theUsername'),
            endurainPassword: EndurainPassword::fromString('thePassword'),
            logger: new NullLogger(),
            clock: PausedClock::fromString(self::TODAY),
            sleep: $sleep,
        );

        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $command = $this->buildCommand(commandBus: $this->commandBus, endurain: $realEndurain);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($application->find(RunEndurainImportAndBuildAppConsoleCommand::NAME));
        $commandTester->execute(['command' => RunEndurainImportAndBuildAppConsoleCommand::NAME]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());

        $dispatchedCommandClasses = array_map(
            static fn (object $command): string => $command::class,
            $this->commandBus->getDispatchedCommands()
        );
        // No new activity: endurain-1 is already imported locally, so the sync loop
        // completing successfully after the 429 retry is what's under test here, not
        // the diffing itself.
        $this->assertNotContains(ImportEndurainActivity::class, $dispatchedCommandClasses);
        $this->assertContains(CalculateActivityMetrics::class, $dispatchedCommandClasses);

        // One retry with a 1-second base backoff.
        $this->assertEquals(1, $sleep->getTotalSleptInSeconds());

        // The mutex must have been released on this successful path too.
        $row = $this->getConnection()->fetchOne(
            'SELECT `value` FROM KeyValue WHERE `key` = :key',
            ['key' => 'lock.importDataOrBuildApp']
        );
        $this->assertFalse($row);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostponesWhenLockIsAlreadyAcquired(): void
    {
        $this->getConnection()->executeStatement(
            'INSERT INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
            ['key' => 'lock.importDataOrBuildApp', 'value' => '{"lockAcquiredBy": "test", "heartbeat": 1764806400}']
        );

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertEmpty($this->commandBus->getDispatchedCommands());
        $this->assertStringContainsString(
            'Postponing Endurain import, another process is importing data.',
            $commandTester->getDisplay(),
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogsAndRethrowsAndReleasesMutexWhenImportFails(): void
    {
        $this->endurain
            ->expects($this->atLeastOnce())
            ->method('getCurrentUserId')
            ->willThrowException(new \RuntimeException('OH NO ERROR'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OH NO ERROR');

        $command = $this->buildCommand(
            commandBus: $this->commandBus,
            logger: $logger,
        );

        $this->expectExceptionObject(new \RuntimeException('OH NO ERROR'));

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($application->find(RunEndurainImportAndBuildAppConsoleCommand::NAME));

        try {
            $commandTester->execute(['command' => RunEndurainImportAndBuildAppConsoleCommand::NAME]);
        } finally {
            // The mutex must have been released even though an exception was thrown.
            $row = $this->getConnection()->fetchOne(
                'SELECT `value` FROM KeyValue WHERE `key` = :key',
                ['key' => 'lock.importDataOrBuildApp']
            );
            $this->assertFalse($row);
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()->build(),
            [],
        ));

        // Endurain::$cachedAccessToken and friends are static: reset them so the real
        // Endurain instance built in testCompletesSuccessfullyWhenGetActivitiesHitsA429MidSyncAndRetriesSucceed()
        // always starts from a clean slate regardless of test execution order.
        Endurain::$cachedAccessToken = null;
        Endurain::$cachedRefreshToken = null;
        Endurain::$cachedAccessTokenExpiresOn = null;

        $this->endurain = $this->createMock(Endurain::class);
        $this->activityRepository = $this->createMock(ActivityRepository::class);
        $this->activityIdRepository = $this->createMock(ActivityIdRepository::class);
        // DetectEndurainActivityChanges is a final, pure class: use the real implementation
        // backed by the mocked ActivityIdRepository rather than trying to double it.
        $this->detectEndurainActivityChanges = new DetectEndurainActivityChanges($this->activityIdRepository);

        $this->command = $this->buildCommand(commandBus: $this->commandBus = new SpyCommandBus());
    }

    private function buildCommand(
        CommandBus $commandBus,
        ?LoggerInterface $logger = null,
        ?Endurain $endurain = null,
    ): RunEndurainImportAndBuildAppConsoleCommand {
        return new RunEndurainImportAndBuildAppConsoleCommand(
            commandBus: $commandBus,
            endurain: $endurain ?? $this->endurain,
            detectEndurainActivityChanges: $this->detectEndurainActivityChanges,
            activityRepository: $this->activityRepository,
            activityIdRepository: $this->activityIdRepository,
            logger: $logger ?? new NullLogger(),
            mutex: new Mutex(
                connection: $this->getConnection(),
                clock: PausedClock::fromString(self::TODAY),
                lockName: LockName::IMPORT_DATA_OR_BUILD_APP,
            ),
            appStatusChecker: new AppStatusChecker(
                $this->getContainer()->get(SettingsRepository::class),
                $this->getContainer()->get(ActivityIdRepository::class),
                new SuccessfulPermissionChecker(),
            ),
        );
    }

    protected function getConsoleCommand(): Command
    {
        return $this->command;
    }

    /**
     * Builds a real-shaped (but fake/test-only) JWT string: three base64url segments
     * separated by '.', with a JSON payload in the middle segment, mirroring the shape
     * of the real Endurain access token without needing valid signing. Mirrors the
     * helper of the same name in EndurainTest, needed here to build a real Endurain
     * instance whose getCurrentUserId() call succeeds.
     *
     * @param array<string, mixed> $payload
     */
    private function buildFakeJwt(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $encode = fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return sprintf(
            '%s.%s.%s',
            $encode(Json::encode($header)),
            $encode(Json::encode($payload)),
            'fake-signature',
        );
    }
}
