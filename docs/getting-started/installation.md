# Installation

> [!NOTE]
> Make sure to read the <a href="/#/getting-started/prerequisites">prerequisites</a> before you start installing the app.

Start off by showing some :heart: and give this repo a star. Then from your command line:

```bash
# Create a new directory
> mkdir statistics-for-endurain
> cd statistics-for-endurain

# Create docker-compose.yml and copy the example contents into it
> touch docker-compose.yml
> nano docker-compose.yml

# Create .env and copy the example contents into it. Configure as you see fit
> touch .env
> nano .env

# Create config.yaml and copy the example contents into it. Configure as you see fit
> touch config/config.yaml
> nano config/config.yaml
```

## docker-compose.yml

```yml
services:
  app:
    image: ghcr.io/salvachillo/statistics-for-endurain:latest
    container_name: statistics-for-endurain
    restart: unless-stopped
    volumes:
      - ./config:/var/www/config/app
      - ./build:/var/www/build
      - ./storage/database:/var/www/storage/database
      - ./storage/files:/var/www/storage/files
    env_file: ./.env
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:2019/metrics"]
      start_period: 60s
    ports:
      - 8080:8080
    networks:
      - statistics-for-endurain-network

  # This container is optional, it is not required to run the app.
  # Its purpose is to handle recurring background tasks, such as:
  #   - Importing and building your Endurain data (app:cron:run-endurain-import)
  #   - Importing local FIT/GPX/TCX files (app:cron:run-file-import)
  #   - Sending notifications when gear maintenance is due
  #   - Sending notifications when a new app version becomes available
  #
  # As of this writing there is no built-in periodic scheduling of the Endurain sync itself — see
  # "Scheduling" for how to trigger it on a recurring basis, and issue #44 for the tracked feature gap.
  #
  # These tasks can be configured in the main configuration file under the `daemon` section:
  #   https://github.com/SalvaChiLlo/statistics-for-endurain/blob/master/docs/configuration/main-configuration.md
  #
  # If you prefer to trigger these tasks manually, you can omit this container entirely.
  daemon:
    image: ghcr.io/salvachillo/statistics-for-endurain:latest
    container_name: statistics-for-endurain-daemon
    restart: unless-stopped
    volumes:
      - ./config:/var/www/config/app
      - ./build:/var/www/build
      - ./storage/database:/var/www/storage/database
      - ./storage/files:/var/www/storage/files
    env_file: ./.env
    healthcheck:
      test: [ "CMD", "sh", "-c", "test -f /var/www/storage/database/dreeve.db && echo 'ok' || exit 1" ]
      start_period: 5s
    command: ['bin/console', 'app:daemon:run']
    networks:
      - statistics-for-endurain-network

networks:
  statistics-for-endurain-network:
```

## .env

> [!IMPORTANT]
> **Important** Every time you change the .env file, you need to recreate (for example; `docker compose up -d`) your container for the changes to take effect (restarting does not update the .env).

```bash
# --- Required, no default is shipped for these ---

# A random secret used by Symfony to sign sessions/CSRF tokens. Omitting this produces an
# unhelpful `{"message": "A non-empty secret is required."}` error with no pointer to the fix.
# Generate one with: openssl rand -hex 16
APP_SECRET=

# The public URL where this instance will be reachable (e.g. https://stats.your-domain.com).
# Omitting this throws "Environment variable not found: APP_URL" when the app tries to render
# the initial setup page.
APP_URL=

# --- Endurain service account ---
# Point the app at a self-hosted Endurain instance and a dedicated (non-MFA) service account.
# Do NOT use your personal Endurain login here if it has MFA enabled — the daemon/import
# commands cannot complete an MFA challenge.
ENDURAIN_URL=https://your-endurain-instance.example.com
ENDURAIN_USERNAME=your-service-account-username
ENDURAIN_PASSWORD=your-service-account-password

# --- Admin panel login ---
# ADMIN_USERNAME defaults to "admin" and ADMIN_PASSWORD_HASH defaults to an empty string, which
# can never successfully log in. To set a real login, generate a bcrypt hash with:
#   docker compose exec app bin/console security:hash-password --no-interaction 'your-password'
# then set both variables below.
#
# IMPORTANT Docker Compose gotcha: a bcrypt hash contains literal `$` characters
# (e.g. $2y$13$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW), and Docker Compose treats `$`
# as variable-interpolation syntax. Every `$` in the hash must be doubled to `$$`, or the value
# will silently break. For example, a generated hash of:
#   $2y$13$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW
# must be written in this .env file (and in a docker-compose.yml `environment:` list) as:
#   $$2y$$13$$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=

# Valid timezones can found under TZ Identifier column here: https://en.wikipedia.org/wiki/List_of_tz_database_time_zones#List
TZ=Etc/GMT

# Uncomment and set these to run the container as a non-root user.
# PUID=your host UID
# PGID=your host GID

# Caddy server log level. Available options: DEBUG, INFO, ERROR
# CADDY_LOG_LEVEL=ERROR
```

## config.yaml

[include](../configuration/config-yaml-example.md ':include')

## Running the application

To run the application run the following command:

```bash
> docker compose up -d
```

The docker container is now running; navigate to the `APP_URL` you configured above (or `http://localhost:8080/` for a local test) to access the app.

## First-run database migration quirk

> [!WARNING]
> **Known rough edge** — on a fresh install, two Doctrine migrations are tied to `config.yaml` and
> `gear-maintenance.yaml` not existing yet. They get **skipped** on their first run (logged as
> "nothing to migrate") but are **not recorded as executed** in the migrations table. This means
> console commands gated by `RequiresUpToDateDatabaseSchema` (including the Endurain import
> command) will refuse to run afterwards with:
> `Your database is not up to date with the migration schema`
> — even though the app just migrated moments ago. Re-running `bin/console app:db:migrate --no-interaction`
> a second time does **not** reliably clear this.
>
> The reliable workaround, run once after first deploying the image:
>
> ```bash
> docker compose exec app bin/console doctrine:migrations:version --add "DoctrineMigrations\Version20260706053720" --no-interaction
> docker compose exec app bin/console doctrine:migrations:version --add "DoctrineMigrations\Version20260625171831" --no-interaction
> ```
>
> (These are the exact migration version identifiers as of this writing; check `migrations/` in the
> version you're running if this doesn't resolve it.) This is being tracked as a rough edge worth a
> proper code fix in a future issue rather than a workaround baked into the docs forever.

## Import and build statistics

Once your `.env` is configured, import your Endurain data and build the HTML files:

```bash
> docker compose exec app bin/console app:cron:run-endurain-import
```

To import local FIT/GPX/TCX files instead (or in addition), see the `IMPORT_MODE` setting in the
main configuration and run:

```bash
> docker compose exec app bin/console app:cron:run-file-import
```

> [!IMPORTANT]
> **Important** Every time you import data, you need to rebuild the HTML files to see the changes — both commands above do this automatically.

## The dashboard stays on "finish setup" until the athlete profile is configured

> [!WARNING]
> The very first import/build will **not** produce a dashboard. `AppStatusChecker::ensureIsReadyForBuild()`
> requires the athlete profile to be configured first, and the import/daemon commands silently catch
> that failure and exit successfully **without building anything** — no error is shown, you just keep
> seeing the "finish setup" placeholder page.
>
> After your first import, go to the admin panel's **General**/**Athlete** settings, fill in and save
> your profile, then run the import/build command again. Only then will the dashboard actually render.

## No automatic sync yet

There is currently no built-in periodic scheduling of the Endurain sync — `app:cron:run-endurain-import`
must be run manually, via the optional `daemon` container (see [Scheduling](scheduling.md)), or via your
own external cron/scheduler. Automatic scheduling is tracked in
[issue #44](https://github.com/SalvaChiLlo/statistics-for-endurain/issues/44).
