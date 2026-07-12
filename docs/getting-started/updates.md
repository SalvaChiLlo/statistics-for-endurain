# Updates

When a new version of the app is released, you need to pull the latest Docker image:

```bash
> docker compose pull # if available pull a new image
> docker compose up -d # start a new container using the compose config and the new pulled image.
```

After that, run the import/build command again to apply the changes:

```bash
> docker compose exec app bin/console app:cron:run-endurain-import
```

> [!NOTE]
> If you're updating a fresh install for the first time, you may hit the
> [first-run migration quirk](/getting-started/installation.md?id=first-run-database-migration-quirk)
> where the import command refuses to run right after a migration. See the installation page for
> the workaround.
