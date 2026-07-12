# Prerequisites

> [!NOTE]
> **Note** To run this application, you'll need <a href="https://docs.docker.com/engine/install/">Docker</a> with <a href="https://docs.docker.com/compose/install/">Docker Compose</a>.

This is an Endurain-only fork: it syncs exclusively against a self-hosted
[Endurain](https://github.com/joaovitoriasilva/endurain) instance, using a dedicated service-account
login rather than an OAuth flow. Before you start the [installation](/getting-started/installation.md),
make sure you have:

* A reachable Endurain instance (its base URL, e.g. `https://endurain.example.com`).
* A **dedicated service account** on that instance for this app to log in as.
  Use a non-MFA account — the daemon/import commands cannot complete an MFA challenge, so an
  MFA-enabled account will fail to authenticate.
* Two required app-level secrets that have no default anywhere in the shipped image:
  * `APP_SECRET` — used by Symfony for session/CSRF signing. Generate one with `openssl rand -hex 16`.
  * `APP_URL` — the public URL this instance will be reachable on.

  Omitting either of these produces confusing errors during setup (see the
  [installation](/getting-started/installation.md) page for details) rather than a clear message
  pointing at the missing variable.

## Map visibility

To view maps of your activities in the app, make sure the activities you import from Endurain
have their location data available (i.e. `hide_map` is not set on the activity in Endurain).
