<?php

declare(strict_types=1);

namespace App\Infrastructure\Daemon\Cron;

use App\Console\Daemon\AppUpdateAvailableNotificationCronAction;
use App\Console\Daemon\GearMaintenanceNotificationConsoleCommand;
use App\Domain\Import\ImportMode;
use App\Infrastructure\Localisation\TranslatableWithDescription;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CronActionId: string implements TranslatableWithDescription
{
    case GEAR_MAINTENANCE_NOTIFICATION = 'gearMaintenanceNotification';
    case APP_UPDATE_AVAILABLE_NOTIFICATION = 'appUpdateAvailableNotification';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION => $translator->trans('Gear maintenance notification', locale: $locale),
            self::APP_UPDATE_AVAILABLE_NOTIFICATION => $translator->trans('App update available notification', locale: $locale),
        };
    }

    public function transDescription(TranslatorInterface $translator, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION => $translator->trans('Sends a notification when gear maintenance is due. Requires a configured notification service.', locale: $locale),
            self::APP_UPDATE_AVAILABLE_NOTIFICATION => $translator->trans('Sends a notification when a new app version is available. Requires a configured notification service.', locale: $locale),
        };
    }

    public function command(): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION => sprintf('bin/console %s', GearMaintenanceNotificationConsoleCommand::NAME),
            self::APP_UPDATE_AVAILABLE_NOTIFICATION => sprintf('bin/console %s', AppUpdateAvailableNotificationCronAction::NAME),
        };
    }

    /**
     * All remaining cron actions are import-mode independent. Kept as a hook (rather than
     * inlining "true" at call sites) since it's a natural extension point should a future
     * cron action need to be gated by import mode again.
     */
    public function supportsImportMode(ImportMode $importMode): bool
    {
        return true;
    }

    public function defaultCronExpression(): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION, self::APP_UPDATE_AVAILABLE_NOTIFICATION => '0 4 * * *',
        };
    }
}
