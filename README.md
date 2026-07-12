<p align="center">
  <img src="public/assets/images/logo.svg" width="250" alt="Logo" >
</p>

<h1 align="center">Statistics for Endurain</h1>

<p align="center">
<a href="https://raw.githubusercontent.com/SalvaChiLlo/statistics-for-endurain/refs/heads/master/LICENSE"><img src="https://img.shields.io/github/license/SalvaChiLlo/statistics-for-endurain?color=428f7e&logo=open%20source%20initiative&logoColor=white" alt="License"></a>
<a href="https://github.com/SalvaChiLlo/statistics-for-endurain/pkgs/container/statistics-for-endurain"><img src="https://img.shields.io/badge/container-ghcr.io-428f7e?logo=docker&logoColor=white" alt="Docker image"></a>
</p>

---

<h4 align="center">Statistics for Endurain is a self-hosted, open-source dashboard for your Endurain data.</h4>

<p align="center">
  <a href="#-documentation">Docs</a> •
  <a href="https://github.com/SalvaChiLlo/statistics-for-endurain/issues">Issues</a>
</p>

## 📸 Showcase

> [!NOTE]
> This is a fork of [robiningelbrecht/statistics-for-strava](https://github.com/robiningelbrecht/statistics-for-strava)
> (branded **Dreeve** upstream). This app is in no way affiliated with or part of the official Strava or Endurain
> software suites.

### Key Features

* **Dashboard** – See all your stats and charts at a glance
* **Activities** -  Browse a detailed list of everything you've done
* **Monthly View** - Monthly stats with an interactive calendar
* **Gear stats** - Track how much you've used each bike, shoe, etc.
* **Custom gear** - Add custom gear setups  ([instructions](docs/configuration/gear-maintenance.md))
* **Maintenance Tracking** - Keep tabs on gear wear and tear ([instructions](docs/configuration/gear-maintenance.md))
* **Eddington** - For your distance milestones
* **Heatmap** - Visualize where you’ve been active the most
* **Milestones** - A timeline view of your key achievements and milestones over time
* **Rewind** - A fun way to look back on your year in motion
* **Activity Photos** - Relive your moments with a photo archive
* **AI workout assistant** - Get personalized workout suggestions and insights powered by AI
* **User badges** - Shareable badges you can embed on your website, blog, or forum profiles
* **PWA support** - Use it like a native app on your phone

## 📖 Documentation

Start off by showing some ❤️ and give this repo a star. Full documentation (installation, configuration,
troubleshooting) lives in [`docs/`](docs/home.md).

This is an Endurain-only fork: it has no Strava OAuth/API integration and syncs exclusively against a self-hosted
[Endurain](https://github.com/joaovitoriasilva/endurain) instance, using a dedicated service-account login rather
than the OAuth flow. Configure `ENDURAIN_URL`, `ENDURAIN_USERNAME` and `ENDURAIN_PASSWORD` in your `.env` file to
point the app at your Endurain instance and account, then run the daemon (or `bin/console app:cron:run-endurain-import`)
to sync activities. Local FIT/GPX/TCX file import remains available independently via `IMPORT_MODE=files`.

Two more `.env` variables are required with no shipped default — see
[docs/getting-started/installation.md](docs/getting-started/installation.md) for details:

* `APP_SECRET` — used by Symfony for session/CSRF signing. Generate one with `openssl rand -hex 16`.
* `APP_URL` — the public URL this instance will be reachable on.

To set a working admin panel login (the shipped defaults can never authenticate), generate a bcrypt hash with
`bin/console security:hash-password --no-interaction '<password>'` and set `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH` —
see the installation page for a Docker Compose `$$`-escaping gotcha with the generated hash.

There is currently no automatic/periodic sync — `app:cron:run-endurain-import` must be run manually or via your
own scheduler/the daemon container (see [issue #44](https://github.com/SalvaChiLlo/statistics-for-endurain/issues/44)).

## 🔁 Migrating from statistics-for-strava

Already have a statistics-for-strava installation with activities, streams and gear downloaded from Strava? You don't need to re-import anything through the Strava API (which is increasingly paywalled/unreliable) — point the migration command at your old SQLite database file and it copies everything straight into this app's database:

```bash
docker compose exec app bin/console app:migrate:from-statistics-for-strava /path/to/old/database.db
```

The command is read-only against the source file (it's never modified) and safe to re-run: activities, streams and gear that were already migrated are skipped rather than duplicated. Migrated activities keep their original `stravaApi` import source, so they stay distinguishable from activities synced from Endurain.

> [!WARNING]
> If you also copy your old `config.yaml` into this app's fresh `config/` volume, and it still contains
> legacy Strava-era settings sections, it can retrigger the same first-run migration quirk described in
> [docs/getting-started/installation.md](docs/getting-started/installation.md#first-run-database-migration-quirk)
> (a migration silently "skips" instead of recording itself as executed, and the import command then refuses
> to run). See that section for the workaround.

