<?php

namespace App\Tests\Infrastructure\Daemon;

use App\Domain\Import\ImportMode;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Daemon\SystemDaemon;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Serialization\Json;
use App\Tests\ContainerTestCase;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use Symfony\Component\Console\Output\BufferedOutput;

class SystemDaemonTest extends ContainerTestCase
{
    private SystemDaemon $systemDaemon;

    public function testClearStaleCronLocksRemovesLeftoverLocks(): void
    {
        foreach (LockName::cases() as $lockName) {
            $this->getConnection()->executeStatement('INSERT INTO KeyValue (key, value) VALUES (:key, :value)', [
                'key' => $lockName->key(),
                'value' => Json::encode([
                    'heartbeat' => 1,
                    'lockAcquiredBy' => 'killed-cron-process',
                ]),
            ]);
        }

        $this->systemDaemon->clearStaleCronLocks();

        foreach (LockName::cases() as $lockName) {
            $this->assertFalse(
                $this->getConnection()->fetchOne(
                    'SELECT `value` FROM KeyValue WHERE `key` = :key',
                    ['key' => $lockName->key()]
                ),
                sprintf('Stale lock "%s" should have been cleared on daemon startup', $lockName->key())
            );
        }
    }

    public function testClearStaleCronLocksWhenNoLocksPresent(): void
    {
        // No leftover locks: clearing on startup must be a harmless no-op.
        $this->systemDaemon->clearStaleCronLocks();

        foreach (LockName::cases() as $lockName) {
            $this->assertFalse($this->getConnection()->fetchOne(
                'SELECT `value` FROM KeyValue WHERE `key` = :key',
                ['key' => $lockName->key()]
            ));
        }
    }

    public function testConfigureCronRegistersEndurainImportWithDefaultSchedule(): void
    {
        putenv('IMPORT_AND_BUILD_SCHEDULE');

        $output = new BufferedOutput();
        $this->systemDaemon->setConsoleOutput($output);
        $this->systemDaemon->configureCron();

        $display = $output->fetch();
        $this->assertStringContainsString('runEndurainImport', $display);
        $this->assertStringContainsString('*/15 * * * *', $display);
    }

    public function testConfigureCronRegistersEndurainImportWithScheduleFromEnvVar(): void
    {
        putenv('IMPORT_AND_BUILD_SCHEDULE=0 2 * * *');

        try {
            $output = new BufferedOutput();
            $this->systemDaemon->setConsoleOutput($output);
            $this->systemDaemon->configureCron();

            $display = $output->fetch();
            $this->assertStringContainsString('runEndurainImport', $display);
            $this->assertStringContainsString('0 2 * * *', $display);
        } finally {
            putenv('IMPORT_AND_BUILD_SCHEDULE');
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->systemDaemon = new SystemDaemon(
            clock: PausedClock::fromString('2025-11-01 10:00:00'),
            settingsRepository: $this->getContainer()->get(SettingsRepository::class),
            importMode: ImportMode::FILES,
            connection: $this->getConnection(),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        // configureCron() registers a live timer on the global React event loop (via
        // WyriHaximus\React\Cron). If left running it keeps the PHP process alive
        // forever once the test suite finishes, hanging the whole paratest worker.
        // Always stop it, regardless of test outcome.
        $this->systemDaemon->stopCron();

        parent::tearDown();
    }
}
