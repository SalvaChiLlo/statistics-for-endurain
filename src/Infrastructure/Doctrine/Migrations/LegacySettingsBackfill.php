<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Migrations;

use App\Domain\Dashboard\DashboardWidgetId;
use App\Domain\Integration\Notification\Shoutrrr\ShoutrrrUrl;
use App\Domain\Settings\SettingsGroup;
use App\Infrastructure\Daemon\Cron\CronActionId;
use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\Connection;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared config.yaml -> KeyValue backfill logic, extracted so it can be
 * reused between the original settings migration (Version20260706053720)
 * and its corrective follow-up (Version20260712140000) without duplicating
 * ~150 lines of parsing/normalization logic.
 *
 * Migration classes under migrations/ are deliberately NOT autoloaded (see
 * config/packages/doctrine_migrations.yaml), so this shared logic lives here
 * under src/ instead, exactly like the migrations already reference
 * SettingsGroup, Key, DashboardWidgetId, etc. from src/.
 *
 * Each method returns null when there is nothing to migrate for that
 * section, so migration classes only need to call $this->addSql() when a
 * non-null value comes back.
 */
final class LegacySettingsBackfill
{
    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>|null
     */
    public static function dashboardLayout(array $config): ?array
    {
        if (!$layout = $config['appearance']['dashboard']['layout'] ?? null) {
            return null;
        }

        // Skip disabled widgets, drop the "enabled" flag, and give each widget an id.
        $layout = array_values(array_filter(
            $layout,
            static fn (array $widget): bool => (bool) ($widget['enabled'] ?? true),
        ));
        foreach ($layout as $i => $widget) {
            unset($widget['enabled']);
            $layout[$i] = ['id' => (string) DashboardWidgetId::random()] + $widget;
        }

        return $layout;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>|null
     */
    public static function generalSettings(array $config, Connection $connection): ?array
    {
        $subtree = $config[SettingsGroup::GENERAL->value] ?? null;
        if (empty($subtree)) {
            return null;
        }

        $subtree = self::normalizeKeys($subtree);
        $subtree = self::applyStoredAthlete($subtree, $connection);

        return self::normalizeAthleteHistories($subtree);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>|null
     */
    public static function appearanceSettings(array $config): ?array
    {
        $subtree = $config[SettingsGroup::APPEARANCE->value] ?? null;
        if (empty($subtree)) {
            return null;
        }

        $subtree = self::normalizeKeys($subtree);
        // The dashboard layout is stored separately under Key::DASHBOARD.
        unset($subtree['dashboard']);
        // Convert the legacy string date format to the modern {short, normal} shape.
        if (isset($subtree['dateFormat']) && is_string($subtree['dateFormat'])) {
            $subtree['dateFormat'] = self::normalizeDateFormat($subtree['dateFormat']);
        }
        if ([] === $subtree) {
            return null;
        }

        return $subtree;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>|null
     */
    public static function metricsSettings(array $config): ?array
    {
        $subtree = $config[SettingsGroup::METRICS->value] ?? null;
        if (empty($subtree)) {
            return null;
        }

        return self::normalizeKeys($subtree);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>|null
     */
    public static function zwiftSettings(array $config): ?array
    {
        $subtree = $config[SettingsGroup::ZWIFT->value] ?? null;
        if (empty($subtree)) {
            return null;
        }

        return self::normalizeKeys($subtree);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>|null
     */
    public static function integrationsSettings(array $config): ?array
    {
        $subtree = $config[SettingsGroup::INTEGRATIONS->value] ?? null;
        if (empty($subtree)) {
            return null;
        }

        $subtree = self::normalizeKeys($subtree);

        // Fold the deprecated ntfy config into a regular notification service URL.
        if (is_array($subtree['notifications'] ?? null)) {
            $notifications = $subtree['notifications'];
            $services = is_array($notifications['services'] ?? null) ? array_values($notifications['services']) : [];

            $ntfyUrl = $notifications['ntfyUrl'] ?? null;
            if (is_string($ntfyUrl) && !in_array($ntfyUrl, ['', '0'], true)) {
                array_unshift($services, (string) ShoutrrrUrl::fromDeprecatedNtfyConfig(
                    ntfyUrl: $ntfyUrl,
                    ntfyUsername: isset($notifications['ntfyUsername']) ? (string) $notifications['ntfyUsername'] : null,
                    ntfyPassword: isset($notifications['ntfyPassword']) ? (string) $notifications['ntfyPassword'] : null,
                ));
            }

            unset($notifications['ntfyUrl'], $notifications['ntfyUsername'], $notifications['ntfyPassword']);
            $notifications['services'] = $services;
            $subtree['notifications'] = $notifications;
        }

        if (isset($subtree['ai']['config']['key'])) {
            // Do not store sensitive data in database.
            unset($subtree['ai']['config']['key']);
        }

        return $subtree;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{cron: array<string, array{expression: string, enabled: bool}>}|null
     */
    public static function daemonSettings(array $config): ?array
    {
        $cron = $config[SettingsGroup::DAEMON->value]['cron'] ?? null;
        if (empty($cron) || !is_array($cron)) {
            return null;
        }

        $validActionIds = array_map(static fn (CronActionId $actionId): string => $actionId->value, CronActionId::cases());

        $actions = [];
        foreach ($cron as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!isset($item['action'])) {
                continue;
            }
            $action = (string) $item['action'];
            if (!in_array($action, $validActionIds, true)) {
                // Drop legacy/removed cron actions (e.g. the Strava-era
                // "importDataAndBuildApp") that no longer have an equivalent.
                continue;
            }
            $actions[$action] = [
                'expression' => (string) ($item['expression'] ?? ''),
                'enabled' => (bool) ($item['enabled'] ?? false),
            ];
        }

        if ([] === $actions) {
            return null;
        }

        return ['cron' => $actions];
    }

    /**
     * @return array{short: string, normal: string}
     */
    private static function normalizeDateFormat(string $legacyDateFormat): array
    {
        [$short, $normal] = match ($legacyDateFormat) {
            'DAY-MONTH-YEAR' => ['d-m-y', 'd-m-Y'],
            'MONTH-DAY-YEAR' => ['m-d-y', 'm-d-Y'],
            default => throw new \InvalidArgumentException(sprintf('Invalid date format "%s"', $legacyDateFormat)),
        };

        return ['short' => $short, 'normal' => $normal];
    }

    /**
     * @param array<array-key, mixed> $subtree
     *
     * @return array<array-key, mixed>
     */
    private static function applyStoredAthlete(array $subtree, Connection $connection): array
    {
        $stored = $connection->fetchOne('SELECT value FROM KeyValue WHERE `key` = :key', ['key' => 'athlete']);
        if (!is_string($stored)) {
            return $subtree;
        }

        $athlete = Json::decode($stored);
        if (!is_array($athlete)) {
            return $subtree;
        }

        $current = is_array($subtree['athlete'] ?? null) ? $subtree['athlete'] : [];
        foreach (['firstname' => 'firstName', 'lastname' => 'lastName', 'sex' => 'gender', 'birthDate' => 'birthday'] as $from => $to) {
            if (!empty($current[$to])) {
                continue;
            }
            if (isset($athlete[$from]) && '' !== (string) $athlete[$from]) {
                $current[$to] = $athlete[$from];
            }
        }
        $subtree['athlete'] = $current;

        return $subtree;
    }

    /**
     * @param array<array-key, mixed> $subtree
     *
     * @return array<array-key, mixed>
     */
    private static function normalizeAthleteHistories(array $subtree): array
    {
        if (!is_array($subtree['athlete'] ?? null)) {
            return $subtree;
        }
        $athlete = $subtree['athlete'];

        $weightHistory = [];
        foreach ((is_array($athlete['weightHistory'] ?? null) ? $athlete['weightHistory'] : []) as $on => $weight) {
            $weightHistory[] = ['on' => (string) $on, 'weight' => $weight];
        }
        $athlete['weightHistory'] = $weightHistory;

        $ftpHistory = is_array($athlete['ftpHistory'] ?? null) ? $athlete['ftpHistory'] : [];
        if (!array_key_exists('cycling', $ftpHistory) && !array_key_exists('running', $ftpHistory)) {
            $ftpHistory = ['cycling' => $ftpHistory, 'running' => []];
        }
        $cycling = [];
        foreach ((is_array($ftpHistory['cycling'] ?? null) ? $ftpHistory['cycling'] : []) as $on => $ftp) {
            $cycling[] = ['on' => (string) $on, 'ftp' => $ftp];
        }
        $running = [];
        foreach ((is_array($ftpHistory['running'] ?? null) ? $ftpHistory['running'] : []) as $on => $ftp) {
            $running[] = ['on' => (string) $on, 'ftp' => $ftp];
        }
        $athlete['ftpHistory'] = ['cycling' => $cycling, 'running' => $running];

        if (is_array($athlete['heartRateZones'] ?? null)) {
            $heartRateZones = $athlete['heartRateZones'];
            $default = is_array($heartRateZones['default'] ?? null) ? $heartRateZones['default'] : [];

            $zones = [];
            foreach (['zone1', 'zone2', 'zone3', 'zone4', 'zone5'] as $name) {
                $zone = is_array($default[$name] ?? null) ? $default[$name] : [];
                $zones[] = ['from' => $zone['from'] ?? null, 'to' => $zone['to'] ?? null];
            }

            $flat = [
                'mode' => $heartRateZones['mode'] ?? 'relative',
                'zones' => $zones,
            ];

            $advanced = [];
            foreach (['dateRanges', 'sportTypes'] as $key) {
                if (isset($heartRateZones[$key])) {
                    $advanced[$key] = $heartRateZones[$key];
                }
            }
            if ([] !== $advanced) {
                $flat['advanced'] = Yaml::dump($advanced, 6, 2);
            }

            $athlete['heartRateZones'] = $flat;
        }

        $subtree['athlete'] = $athlete;

        return $subtree;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function normalizeKeys(array $config): array
    {
        $normalized = [];
        foreach ($config as $key => $value) {
            if (is_string($key) && str_contains($key, '_')) {
                $key = lcfirst(str_replace('_', '', ucwords($key, '_')));
            }
            $normalized[$key] = is_array($value) ? self::normalizeKeys($value) : $value;
        }

        return $normalized;
    }
}
