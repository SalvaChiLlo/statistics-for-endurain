<?php

declare(strict_types=1);

namespace App\Infrastructure\Daemon;

use App\Console\Daemon\RunEndurainImportAndBuildAppConsoleCommand;
use App\Console\Daemon\RunFileImportAndBuildAppConsoleCommand;
use App\Domain\Import\ImportMode;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Console\ConsoleOutputAware;
use App\Infrastructure\Daemon\Cron\CronAction;
use App\Infrastructure\Daemon\Cron\CronProcess;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Time\Clock\Clock;
use Doctrine\DBAL\Connection;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use WyriHaximus\React\Cron;
use WyriHaximus\React\Cron\Action;

use function React\Promise\resolve;

/**
 * @codeCoverageIgnore
 */
final class SystemDaemon implements Daemon
{
    use ConsoleOutputAware;

    private const string CRON_EVERY_5_MINUTES = '*/5 * * * *';

    /**
     * Unlike Strava's rate-limited API, Endurain is self-hosted, so a fairly aggressive
     * default sync interval is safe and keeps the dashboard close to real-time. Every 15
     * minutes strikes a balance between freshness and not hammering the Endurain instance.
     */
    private const string CRON_EVERY_15_MINUTES = '*/15 * * * *';

    private const string IMPORT_AND_BUILD_SCHEDULE_ENV_VAR = 'IMPORT_AND_BUILD_SCHEDULE';

    /**
     * Constructing a Cron instance immediately registers a live timer on the global
     * React event loop (see WyriHaximus\React\Cron\Scheduler::align()). We keep a
     * reference so it can be stopped explicitly (e.g. in tests, via stopCron()) —
     * otherwise the timer keeps ticking forever and the process never exits.
     */
    private ?Cron $cron = null;

    public function __construct(
        private readonly Clock $clock,
        private readonly SettingsRepository $settingsRepository,
        private readonly ImportMode $importMode,
        private readonly Connection $connection,
    ) {
    }

    public function addPeriodicDebugTimer(): void
    {
        Loop::addPeriodicTimer(1.0, function (): void {
            $this->getConsoleOutput()->writeln(sprintf(
                '[%s] Periodic debug timer',
                $this->clock->getCurrentDateTimeImmutable()->format('H:i:s'),
            ));
        });
    }

    public function clearStaleCronLocks(): void
    {
        // On startup no cron child process is running yet, so any lock still present
        // in the KeyValue table is stale.
        foreach (LockName::cases() as $lockName) {
            new Mutex(
                connection: $this->connection,
                clock: $this->clock,
                lockName: $lockName,
            )->releaseLock();
        }
    }

    public function configureCron(): void
    {
        $actions = [];
        $processedCronAction = [];
        /** @var CronAction $cronAction */
        foreach ($this->settingsRepository->daemon()->getConfiguredCronActions() as $cronAction) {
            if (!$cronAction->supportsImportMode($this->importMode)) {
                continue;
            }
            $processedCronAction[] = $cronAction;
            $actions[] = new Action(
                key: $cronAction->getId()->value,
                mutexTtl: 1200,
                expression: (string) $cronAction->getExpression(),
                performer: function () use ($cronAction): PromiseInterface {
                    $process = new CronProcess(
                        cronActionId: $cronAction->getId()->value,
                        clock: $this->clock,
                        output: $this->getConsoleOutput(),
                        command: $cronAction->getCommand(),
                    );
                    $process->start();

                    return resolve(true);
                }
            );
        }

        $extraConfiguredCronActionsOutput = [];

        if ($this->importMode->isFiles()) {
            $extraConfiguredCronActionsOutput[] = sprintf('<info> - runFileImport: %s</info>', self::CRON_EVERY_5_MINUTES);
            $actions[] = new Action(
                key: 'runFileImport',
                mutexTtl: 300,
                expression: self::CRON_EVERY_5_MINUTES,
                performer: function (): PromiseInterface {
                    $process = new CronProcess(
                        cronActionId: 'runFileImport',
                        clock: $this->clock,
                        output: $this->getConsoleOutput(),
                        command: sprintf('bin/console %s', RunFileImportAndBuildAppConsoleCommand::NAME)
                    );
                    $process->start();

                    return resolve(true);
                }
            );
        }

        $endurainImportSchedule = $this->resolveEndurainImportSchedule();
        $extraConfiguredCronActionsOutput[] = sprintf('<info> - runEndurainImport: %s</info>', $endurainImportSchedule);
        $actions[] = new Action(
            key: 'runEndurainImport',
            mutexTtl: 1200,
            expression: $endurainImportSchedule,
            performer: function (): PromiseInterface {
                $process = new CronProcess(
                    cronActionId: 'runEndurainImport',
                    clock: $this->clock,
                    output: $this->getConsoleOutput(),
                    command: sprintf('bin/console %s', RunEndurainImportAndBuildAppConsoleCommand::NAME)
                );
                $process->start();

                return resolve(true);
            }
        );

        $this->cron = Cron::create(...$actions);
        $this->cron->on('error', function (\Throwable $throwable): void {
            $this->getConsoleOutput()->writeln(sprintf('<error>%s</error>', $throwable->getMessage()));
        });

        // Endurain sync is always registered unconditionally above, so $actions can no longer be empty.
        $this->getConsoleOutput()->writeln(sprintf('<info>%s</info>', 'Cron configured'));
        $this->getConsoleOutput()->writeln([
            ...array_map(
                fn (CronAction $action): string => \sprintf('<info> - %s: %s</info>', $action->getId()->value, $action->getExpression()),
                $processedCronAction
            ),
            ...$extraConfiguredCronActionsOutput,
        ]);
    }

    /**
     * Stops the scheduler timer registered on the global event loop by configureCron().
     * The production entrypoint (the long-running daemon process) never needs to call
     * this, but tests that invoke configureCron() must call it afterwards — otherwise
     * the timer keeps the PHP process (and, under paratest, the whole worker) alive
     * indefinitely.
     */
    public function stopCron(): void
    {
        $this->cron?->stop();
        $this->cron = null;
    }

    /**
     * Endurain sync is unconditional (not gated by ImportMode) and, unlike the old
     * Strava import, isn't rate-limited, so a fixed default schedule is sensible.
     * It's still overridable via IMPORT_AND_BUILD_SCHEDULE for deployments that want
     * a less (or more) frequent sync.
     */
    private function resolveEndurainImportSchedule(): string
    {
        $configuredSchedule = trim((string) getenv(self::IMPORT_AND_BUILD_SCHEDULE_ENV_VAR));

        return '' !== $configuredSchedule ? $configuredSchedule : self::CRON_EVERY_15_MINUTES;
    }
}
