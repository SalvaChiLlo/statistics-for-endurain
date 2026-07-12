<p class="flex justify-center">
  <img width="250" src="https://raw.githubusercontent.com/SalvaChiLlo/statistics-for-endurain/master/public/assets/images/logo.svg" alt="Logo">
</p>

<h4 class="text-center">Statistics for Endurain is a self-hosted, open-source dashboard for your sports and fitness data</h4>

<div class="text-center">
<a href="https://raw.githubusercontent.com/SalvaChiLlo/statistics-for-endurain/refs/heads/master/LICENSE"><img src="https://img.shields.io/github/license/SalvaChiLlo/statistics-for-endurain?color=428f7e&logo=open%20source%20initiative&logoColor=white" alt="License"></a>
<a href="https://github.com/SalvaChiLlo/statistics-for-endurain/pkgs/container/statistics-for-endurain"><img src="https://img.shields.io/badge/container-ghcr.io-428f7e?logo=docker&logoColor=white" alt="Docker image"></a>
</div>

---

> [!NOTE]
> This is a fork of [robiningelbrecht/statistics-for-strava](https://github.com/robiningelbrecht/statistics-for-strava) (branded **Dreeve** upstream)
> converted to sync exclusively against a self-hosted [Endurain](https://github.com/joaovitoriasilva/endurain) instance using a dedicated
> service-account login. All Strava OAuth/API integration, webhooks, segment import and the Strava trophy-case/challenges import have been
> removed. Local FIT/GPX/TCX file import remains available independently.

# :camera_flash: Showcase

> [!NOTE]
> **Note** This app is in no way affiliated with or part of the official Strava or Endurain software suites.

## Key Features

* **Dashboard** – See all your stats and charts at a glance
* **Activities** -  Browse a detailed list of everything you've done
* **Monthly View** - Monthly stats with an interactive calendar
* **Gear stats** - Track how much you've used each bike, shoe, etc.
* **Track gear** - Track custom gear setups
* **Maintenance Tracking** - Keep tabs on gear wear and tear ([instructions](configuration/gear-maintenance.md))
* **Eddington** - For your distance milestones
* **Heatmap** - Visualize where you've been active the most
* **Milestones** - A timeline view of your key achievements and milestones over time
* **Rewind** - A fun way to look back on your year in motion
* **Activity Photos** - Relive your moments with a photo archive
* **AI workout assistant** - Get personalized workout suggestions and insights powered by AI ([instructions](configuration/ai-integration.md))
* **User badges** - Shareable badges you can embed on your website, blog, or forum profiles
* **PWA support** - Use it like a native app on your phone

> [!WARNING]
> * **Backup before updates**: Always backup your Docker volumes before upgrading.
> * **Stay up-to-date**: Make sure you're running the latest version for the best experience.
> * **Check the release notes**: Always check the [release notes](https://github.com/SalvaChiLlo/statistics-for-endurain/releases) to verify if there are any breaking changes.
> * **Automatic sync**: once the optional daemon container is running, activities are pulled from Endurain automatically every 15 minutes by default (configurable via the `IMPORT_AND_BUILD_SCHEDULE` env var). See [main configuration](configuration/main-configuration.md#automatic-endurain-sync).
