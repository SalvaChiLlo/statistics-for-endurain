<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine\Migrations;

use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Regression coverage for the SettingsGroup::IMPORT crash.
 *
 * Version20260706053720 used to unconditionally call migrateImport(), which
 * referenced the (never-existing) SettingsGroup::IMPORT enum case. That threw
 * a PHP Error whenever a real config.yaml was present, i.e. on essentially
 * every first-run deploy. Because AbstractMigration::addSql() only queues SQL
 * to be executed after up() returns without throwing, the crash meant *none*
 * of the settings (dashboard/general/appearance/metrics/zwift/integrations/
 * daemon) were ever persisted either - the whole migration was a no-op that
 * looked like a hard failure.
 *
 * Some real deployments worked around this by force-marking the migration as
 * executed (`doctrine:migrations:version --add`), which leaves those
 * instances with the version marked "done" but none of the settings actually
 * migrated. Version20260712140000 is a corrective follow-up that re-applies
 * the same (now-fixed) backfill so those instances catch up.
 */
final class Version20260706053720Test extends KernelTestCase
{
    private const string CONFIG_FIXTURE = __DIR__.'/../../../app-configs/config-ai-enabled/config/app/config.yaml';

    private string $configYamlPath;
    private KernelInterface $migrationKernel;

    public function testFreshInstallWithConfigYamlMigratesWithoutCrashingOnImport(): void
    {
        $connection = $this->freshlyMigrate('latest');

        $general = $this->fetchSettings($connection, 'settingsGeneral');
        $this->assertSame('Robin The King 👑', $general['appSubTitle']);
        $this->assertSame('1989-08-14', $general['athlete']['birthday']);

        $appearance = $this->fetchSettings($connection, 'settingsAppearance');
        $this->assertSame('en_US', $appearance['locale']);
        $this->assertSame('metric', $appearance['unitSystem']);

        $zwift = $this->fetchSettings($connection, 'settingsZwift');
        $this->assertSame(80, $zwift['level']);
        $this->assertSame(495, $zwift['racingScore']);

        $integrations = $this->fetchSettings($connection, 'settingsIntegrations');
        $this->assertSame('openAI', $integrations['ai']['provider']);

        // The fixture's "import:" section (a leftover Strava-era config
        // section) must not have produced any KeyValue row: there is no
        // SettingsGroup::IMPORT case, so nothing should ever be written for
        // it, and no crash should have prevented the other sections above
        // from being migrated.
        $this->assertFalse($this->keyExists($connection, 'settingsImport'));
    }

    public function testForceMarkedVersionWithoutHavingRunGetsBackfilledByCorrectiveMigration(): void
    {
        // Simulate the documented real-world workaround: migrate everything
        // up to (but not including) the buggy migration, then force-mark it
        // as executed exactly like `doctrine:migrations:version --add` would,
        // without ever running its SQL.
        $connection = $this->freshlyMigrate('DoctrineMigrations\\Version20260625171831');
        $connection->executeStatement(
            'INSERT INTO migration_versions (version, executed_at, execution_time) VALUES (:version, :executedAt, 0)',
            [
                'version' => 'DoctrineMigrations\\Version20260706053720',
                'executedAt' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]
        );

        $this->assertFalse($this->keyExists($connection, 'settingsGeneral'), 'Sanity check: nothing migrated yet.');

        // Running the rest of the migrations (including the corrective
        // Version20260712140000) must backfill the settings that the
        // force-marked, never-actually-run migration was supposed to write.
        $this->runMigrateCommand('latest');

        $general = $this->fetchSettings($connection, 'settingsGeneral');
        $this->assertSame('Robin The King 👑', $general['appSubTitle']);

        $appearance = $this->fetchSettings($connection, 'settingsAppearance');
        $this->assertSame('en_US', $appearance['locale']);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationKernel = self::bootKernel();
        $this->configYamlPath = $this->migrationKernel->getProjectDir().'/config/app/config.yaml';

        $filesystem = new Filesystem();
        $filesystem->copy(self::CONFIG_FIXTURE, $this->configYamlPath, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->configYamlPath);

        parent::tearDown();
    }

    private function freshlyMigrate(string $version): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Start from a completely empty database.
        new SchemaTool($entityManager)->dropDatabase();
        $connection->executeStatement('DROP TABLE IF EXISTS migration_versions');

        $this->runMigrateCommand($version);

        return $connection;
    }

    private function runMigrateCommand(string $version): void
    {
        $application = new Application($this->migrationKernel);
        $application->setAutoExit(false);
        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => $version,
                '--no-interaction' => true,
                '--allow-no-migration' => true,
            ]),
            $output = new BufferedOutput()
        );
        $this->assertSame(0, $exitCode, 'Running migrations failed: '.$output->fetch());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSettings(Connection $connection, string $key): array
    {
        $raw = $connection->fetchOne('SELECT value FROM KeyValue WHERE `key` = :key', ['key' => $key]);
        $this->assertIsString($raw, "Expected a KeyValue row for key \"{$key}\".");

        $decoded = Json::decode($raw);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function keyExists(Connection $connection, string $key): bool
    {
        return false !== $connection->fetchOne('SELECT value FROM KeyValue WHERE `key` = :key', ['key' => $key]);
    }
}
