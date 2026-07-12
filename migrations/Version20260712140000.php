<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Domain\Settings\SettingsGroup;
use App\Infrastructure\Doctrine\Migrations\LegacySettingsBackfill;
use App\Infrastructure\KeyValue\Key;
use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Corrective follow-up for Version20260706053720.
 *
 * Version20260706053720 unconditionally referenced the (never-existing)
 * SettingsGroup::IMPORT enum case, so on any real deployment with a
 * config.yaml present it threw a PHP Error before any of its addSql()
 * statements were ever executed by the migration runner. In practice this
 * meant the migration either:
 *  - failed outright and was never marked as executed, or
 *  - was manually force-marked as executed via
 *    `doctrine:migrations:version --add` to unblock the deploy, which left
 *    the migrations table believing this version had run while none of its
 *    config.yaml -> KeyValue backfill (dashboard/general/appearance/metrics/
 *    zwift/integrations/daemon settings) had actually happened.
 *
 * Version20260706053720 has already shipped in tagged releases (v5.0.0,
 * v5.1.0), so instances that force-marked it as executed will never re-run
 * its (now fixed) logic on their own. This migration re-applies the same
 * config.yaml backfill so those instances end up with the settings they
 * should have gotten in the first place. It is idempotent (REPLACE INTO) so
 * it is harmless to run again even on instances where Version20260706053720
 * did complete successfully after being fixed.
 */
final class Version20260712140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-apply the config.yaml settings backfill from Version20260706053720 for instances where that migration was force-marked as executed without ever running (due to the SettingsGroup::IMPORT crash).';
    }

    public function up(Schema $schema): void
    {
        $basePath = dirname(__DIR__).'/config/app';
        $configFile = $basePath.'/config.yaml';

        $this->skipIf(
            !file_exists($configFile),
            'No config.yaml found, nothing to migrate'
        );

        $finder = Finder::create()
            ->in($basePath)
            ->depth('== 0')
            ->files()
            ->sortByName()
            ->name('config-*.yaml');

        $config = Yaml::parseFile($configFile);
        foreach ($finder as $file) {
            try {
                $config = array_replace_recursive($config, Yaml::parseFile($file->getRealPath()));
            } catch (ParseException) {
            }
        }

        if (null !== $layout = LegacySettingsBackfill::dashboardLayout($config)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => Key::DASHBOARD->value, 'value' => Json::encode($layout)]
            );
        }

        if (null !== $general = LegacySettingsBackfill::generalSettings($config, $this->connection)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => SettingsGroup::GENERAL->keyValueKey()->value, 'value' => Json::encode($general)]
            );
        }

        if (null !== $appearance = LegacySettingsBackfill::appearanceSettings($config)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => SettingsGroup::APPEARANCE->keyValueKey()->value, 'value' => Json::encode($appearance)]
            );
        }

        if (null !== $metrics = LegacySettingsBackfill::metricsSettings($config)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => SettingsGroup::METRICS->keyValueKey()->value, 'value' => Json::encode($metrics)]
            );
        }

        if (null !== $zwift = LegacySettingsBackfill::zwiftSettings($config)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => SettingsGroup::ZWIFT->keyValueKey()->value, 'value' => Json::encode($zwift)]
            );
        }

        if (null !== $integrations = LegacySettingsBackfill::integrationsSettings($config)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => SettingsGroup::INTEGRATIONS->keyValueKey()->value, 'value' => Json::encode($integrations)]
            );
        }

        if (null !== $daemon = LegacySettingsBackfill::daemonSettings($config)) {
            $this->addSql(
                'REPLACE INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
                ['key' => SettingsGroup::DAEMON->keyValueKey()->value, 'value' => Json::encode($daemon)]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // This migration only ever re-applies the same idempotent backfill
        // Version20260706053720 already owns; that migration's own down()
        // is responsible for deleting the resulting KeyValue rows.
    }
}
