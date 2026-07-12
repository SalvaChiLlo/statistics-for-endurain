# FAQ

## Why does it take so long to import my data?

Running the import for the first time can take a while, depending on how many activities you have on
your Endurain instance. The Endurain import paginates through your activities and, for each one, fetches
its streams — for a large history this can take a while, especially on the first run.

## Can I sync multiple Endurain accounts?

No, the app only supports one Endurain service account at a time. If you want to sync multiple accounts,
you will need to run multiple instances of the app, each pointed at its own `ENDURAIN_USERNAME`/`ENDURAIN_PASSWORD`.

## Can I manage my settings through the UI?

Yes, for the settings the admin panel currently exposes (including the athlete profile, which you must
fill in and save before the dashboard will build — see the
[installation page](/getting-started/installation.md?id=the-dashboard-stays-on-finish-setup-until-the-athlete-profile-is-configured)).
Some more advanced/less common settings are still only configurable through the `config.yaml` file(s) —
see [Main configuration](/configuration/main-configuration.md).

## Why does the dashboard just show a "finish setup" page after I imported data?

See [this note on the installation page](/getting-started/installation.md?id=the-dashboard-stays-on-finish-setup-until-the-athlete-profile-is-configured) —
the athlete profile needs to be configured via the admin panel before a build will actually produce a dashboard.

## Does the app sync automatically?

Yes, if you run the optional daemon container — it syncs against Endurain every 15 minutes by default,
configurable via the `IMPORT_AND_BUILD_SCHEDULE` env var. See
[Scheduling](/getting-started/scheduling.md) and
[main configuration](/configuration/main-configuration.md?id=automatic-endurain-sync) for details.
