# Endurain API reference (confirmed against a live instance)

Captured 2026-07-11 against a real Endurain deployment
(`codeberg.org/endurain-project/endurain:latest`, `ENVIRONMENT=development`
temporarily enabled to pull the OpenAPI spec) with a throwaway test account
and a handful of bulk-imported activities. This supersedes assumptions made
from source-code reading alone in the original design spec — several details
below correct or refine what was assumed there.

Example values below are **sanitized placeholders** illustrating field
names/types/units, not the real captured data (the real activities used to
verify this contain personal GPS/location/health data and were not copied
into this doc).

OpenAPI spec pulled from `GET /openapi.json` (not `/api/v1/docs` — that path
404s; the spec itself is served unprefixed).

## Authentication

`POST /api/v1/auth/login`

- Body: `application/x-www-form-urlencoded` — `username`, `password`
  required (`grant_type`, `scope`, `client_id`, `client_secret` optional/
  unused for our purposes).
- **Required header: `X-Client-Type: mobile`** (or `web`). Without it, the
  endpoint itself still 401s with `{"detail":"Not authenticated"}` —
  confirmed by testing without the header first.
- With `X-Client-Type: mobile`, the response is a flat JSON body
  (`TokenResponseMobile`):
  ```json
  {
    "session_id": "...",
    "access_token": "...",
    "refresh_token": "...",
    "token_type": "bearer",
    "expires_in": 899,
    "refresh_token_expires_in": 604799
  }
  ```
  This is a better fit for our headless daemon than the `web` flow
  (`TokenResponseWeb`), which omits `refresh_token` from the body entirely
  and instead sets it as an httpOnly cookie (`endurain_refresh_token`) plus
  a separate `csrf_token` for cookie-based requests. **Use `X-Client-Type:
  mobile` for the daemon client**, not `web`.
- `access_token` lifetime: ~15 minutes (`expires_in: 899`).
- `refresh_token` lifetime: ~7 days (`refresh_token_expires_in: 604799`).
- The decoded JWT `access_token` carries `sub` = the numeric user ID (no
  separate "get my user id" call needed after login) and a `scope` array
  reflecting the account's granted scopes (e.g. `activities:read`,
  `activities:write`, `activities:upload`, `gears:read`, `gears:write`, ...).
  No MFA was configured on the test account, so the plain-login path
  returned tokens directly — an MFA-enabled service account would instead
  need the `/api/v1/auth/mfa/verify` step, so **use a non-MFA service
  account for the daemon**, per the original design spec's recommendation.

`POST /api/v1/auth/refresh`

- No request body. Send the **refresh token** as the bearer credential:
  `Authorization: Bearer <refresh_token>`, plus `X-Client-Type: mobile`.
- Returns a new `TokenResponseMobile` with a **rotated** `refresh_token`
  (different value each call) — confirmed by testing. The client must
  persist the new refresh token after every refresh, not reuse the original.

**Every authenticated request needs both headers**, not just `Authorization`:
`Authorization: Bearer <access_token>` AND `X-Client-Type: mobile`. Omitting
`X-Client-Type` produces a 401 even with a valid, unexpired access token —
confirmed by testing `GET /api/v1/activities/user/{id}/page_number/1/num_records/5`
first without the header (401 `{"detail":"Not authenticated"}`) and then with
it (200, real data).

## Activities

`GET /api/v1/activities/user/{user_id}/page_number/{page_number}/num_records/{num_records}`

- Path params only for pagination (no `?page=&per_page=`), plus optional
  query params: `type`, `start_date`, `end_date`, `name_search`, `sort_by`,
  `sort_order` (per OpenAPI spec; not all exercised in this capture).
- **Returns a bare JSON array of full `Activity` objects** — not a
  pagination-wrapper object, no `total`/`count` field. To know when to stop
  paging, the caller must detect an empty array or a short page.
- Confirmed real fields on an `Activity` (sanitized example, ride type):

  ```json
  {
    "id": 1,
    "user_id": 1,
    "description": null,
    "private_notes": null,
    "distance": 18736,
    "name": "Workout",
    "activity_type": 4,
    "start_time": "2026-06-22T19:11:56",
    "start_time_tz_applied": "2026-06-22T19:11:56",
    "end_time": "2026-06-22T20:21:21",
    "end_time_tz_applied": "2026-06-22T20:21:21",
    "timezone": "Europe/Madrid",
    "total_elapsed_time": 4165.0,
    "total_timer_time": 4024.0,
    "city": null,
    "town": null,
    "country": null,
    "created_at": "2026-07-11T18:40:55",
    "created_at_tz_applied": "2026-07-11T18:40:55",
    "elevation_gain": 57,
    "elevation_loss": 66,
    "pace": 0.2147712905,
    "average_speed": 4.656,
    "max_speed": 12.765,
    "average_power": null,
    "max_power": null,
    "normalized_power": null,
    "average_hr": null,
    "max_hr": null,
    "average_cad": null,
    "max_cad": null,
    "workout_feeling": null,
    "workout_rpe": null,
    "calories": null,
    "visibility": 0,
    "gear_id": null,
    "strava_gear_id": null,
    "strava_activity_id": null,
    "garminconnect_activity_id": null,
    "garminconnect_gear_id": null,
    "import_info": {
      "imported": true,
      "import_source": "Basic bulk import",
      "import_ISO_time": "2026-07-11T16:35:04.421037+00:00"
    },
    "is_hidden": false,
    "hide_start_time": false,
    "hide_location": false,
    "hide_map": false,
    "hide_hr": false,
    "hide_power": false,
    "hide_cadence": false,
    "hide_elevation": false,
    "hide_speed": false,
    "hide_pace": false,
    "hide_laps": false,
    "hide_workout_sets_steps": false,
    "hide_gear": false,
    "tracker_manufacturer": null,
    "tracker_model": null,
    "map_thumbnail_path": "/app/backend/data/activity_thumbnails/1.png"
  }
  ```

- **Corrections to assumptions in the original design spec**, based on real
  data:
  - `average_speed` and `max_speed` are **meters/second** (confirmed: an
    18.7 km ride with `total_timer_time` 4024s produced `average_speed`
    4.656, i.e. `distance / total_timer_time`) — the design spec guessed
    these were seconds-per-meter like `pace`. Only `pace` is
    seconds-per-meter (`total_timer_time / distance`).
    **Unit conversion (#10) must treat `average_speed`/`max_speed` and
    `pace` differently.**
  - There is no single `device_name` field — device info is two separate
    fields, `tracker_manufacturer` and `tracker_model`.
  - `import_info` is a nested object (`imported`, `import_source`,
    `import_ISO_time`), not flat fields.
  - No `Decimal(20,10)`-style string serialization was observed — numeric
    fields come back as plain JSON floats/ints over the wire, so precision
    handling in #10 is about float precision, not decimal-string parsing.
- `activity_type: 4` (ride) confirmed in this capture; full int-vocabulary
  mapping (#9) still needs to be built against Endurain's source/docs since
  only ride-type test data was exercised here.

`GET /api/v1/activities/{id}` — single activity, **same field shape** as a
list item (confirmed identical structure via both endpoints).

## Gears

`GET /api/v1/gears`

- **Different response shape from activities** — a pagination-wrapper
  object, not a bare array:
  ```json
  {"total": 0, "num_records": null, "page_number": null, "records": []}
  ```
- Not exercised with real gear data (test account has none). #5 should
  create a gear item on the test account and re-capture a populated example
  before finalizing the gear import mapping.
- `GET /api/v1/gears/id/{gear_id}` fetches a single gear item by id (used by
  #5's on-demand, single-activity-scoped gear import instead of the list
  endpoint above).
- Field shape and `gear_type` vocabulary **confirmed against Endurain's
  source** (`backend/app/gears/gear/models.py` and `schema.py` on
  `endurain-project/endurain@master`), not against a live populated
  instance (still an open item — no real gear payload has been captured):
  ```json
  {
    "id": 42,
    "user_id": 1,
    "brand": "Canyon",
    "model": "Grail",
    "nickname": "My Commuter Bike",
    "gear_type": 1,
    "created_at": "2026-01-01T12:00:00",
    "active": true,
    "initial_kms": 0.0,
    "purchase_value": null,
    "strava_gear_id": null,
    "garminconnect_gear_id": null
  }
  ```
  `gear_type` vocabulary: `1`=bike, `2`=shoes, `3`=wetsuit, `4`=racquet,
  `5`=skis, `6`=snowboard, `7`=windsurf, `8`=water sports board. This
  codebase's `Gear` entity does not model equipment category (only
  import-provenance via `GearType::IMPORTED`/`CUSTOM`), so `gear_type` is
  read but intentionally not translated anywhere — see
  `EndurainGearTranslator`.

## Streams

`GET /api/v1/activities_streams/activity_id/{activity_id}/all`

- Returns a **JSON array of stream-type objects**, one per stream type that
  actually has data for the activity — types with no data are simply
  **omitted from the array**, not returned as an empty entry. For the
  captured cycling activity (no power meter, no cadence sensor), only types
  `1, 4, 5, 6, 7, 8` were present; `2` (power) and `3` (cadence) were absent
  entirely.
- Each stream object:
  ```json
  {
    "id": 1,
    "activity_id": 1,
    "stream_type": 1,
    "stream_waypoints": [ { "time": "2026-06-22T17:11:56", "hr": 105 }, ... ],
    "strava_activity_stream_id": null,
    "hr_zone_percentages": null
  }
  ```
- **Correction to the original design spec's open question**: each waypoint
  dict **does include a `time` key** (ISO 8601 string, no timezone offset —
  matches the activity's UTC-naive `start_time` style), contradicting the
  earlier assumption that streams had no shared time axis. Reconstructing a
  shared time axis (#4) can align waypoints across stream types by matching
  `time` values directly, rather than needing to infer an index-based axis.
- Per-type value key and observed unit, confirmed from real waypoints
  (values below are illustrative, not the real captured numbers):
  | `stream_type` | meaning | value key | unit/format |
  |---|---|---|---|
  | 1 | Heart rate | `hr` | bpm, int |
  | 4 | Elevation | `ele` | meters, float |
  | 5 | Speed | `vel` | meters/second, float |
  | 6 | Pace | `pace` | seconds/meter, float |
  | 7 | Map (lat/lng) | `lat`, `lon` | decimal degrees, float |
  | 8 | Temperature | `temp` | °C, int |
  | 2 | Power | *(not observed — no power-meter data in this capture)* | — |
  | 3 | Cadence | *(not observed — no cadence data in this capture)* | — |
- **Waypoint counts differ slightly across stream types for the same
  activity** (confirmed: HR/temperature points outnumbered speed/pace/map
  points by a handful in this capture) — sensors don't all report at
  identical intervals. #4's time-axis reconstruction must align by
  matching/nearest `time` value per stream type, not by assuming
  equal-length parallel arrays like Strava's `/streams` response.
- No `moving` or `grade_smooth` stream types exist, confirming the design
  spec's assumption — #4 should omit these series rather than trying to
  derive them, unless a later issue decides they're worth computing from
  `vel`/`ele` deltas.

## Open items for later issues

- Gear response needs re-capturing once #5 creates a real gear item (see
  above).
- Full `activity_type` int vocabulary (beyond the single `4 = ride` value
  seen here) still needs confirming against Endurain's source or docs for
  #9 — this capture only had ride-type test data.
- Power (`stream_type: 2`) and cadence (`stream_type: 3`) waypoint shapes
  are unconfirmed — assume `{"time": ..., "watts": ...}` /
  `{"time": ..., "cad": ...}` by analogy with the confirmed types until a
  real power/cadence-equipped activity is captured, and verify before
  relying on it in #4.
