# gonbi-digital/laravel-heartbeat

A health endpoint that answers when the database does not, and an ops page for the humans.

Two routes, deliberately separate:

| Route        | For       | Shape                    | Gate                       | Middleware   |
| ------------ | --------- | ------------------------ | -------------------------- | ------------ |
| `/health`     | a monitor | the JSON contract      | bearer token, else **404** | **none**      |
| `/health/ops` | a person  | a standalone HTML page | signed in, or the token    | `web` + own gate |

## Why they are separate

Laravel ships with `SESSION_DRIVER=database` as a common default, and plenty of apps keep it.

A health route inside the `web` middleware group therefore boots a session out of the very
database it is being asked about. The moment that database goes away, `StartSession` throws and
the monitor gets a 500 with no explanation — instead of `database: SQLSTATE[HY000] [2002]
Connection refused`. The endpoint fails exactly when it is needed, and says nothing about the
failure it exists to report.

So `/health` carries **no middleware at all** and the token is the credential. `/health/ops` can
afford a session, because the person reading it is not the thing that has to keep working during
the outage.

The page sits under `health/` rather than at `/heartbeat` so an app that already has a heartbeat
page of its own keeps it, untouched, and gains only the machine endpoint. Set `HEARTBEAT_PAGE=false`
there and the package registers nothing but `/health`.

## Install

```bash
composer require gonbi-digital/laravel-heartbeat
```

The provider is auto-discovered and both routes register themselves. Set the token:

```dotenv
HEARTBEAT_TOKEN=some-long-random-string
```

Leave it unset and `/health` is open to anyone who finds it — fine locally, never in production:
the payload names every driver the app runs on.

Publish the config only if you need to change something:

```bash
php artisan vendor:publish --tag=heartbeat-config
```

## The contract

```json
{
    "status": "ok",
    "checks": {
        "database": { "status": "ok", "message": "mysql · app_production", "ms": 3 },
        "cache": { "status": "ok", "message": "read back what was written to redis", "ms": 1 },
        "storage": { "status": "ok", "message": "read back what was written to the s3 disk", "ms": 41 },
        "queue": { "status": "degraded", "message": "1204 jobs pending, above the 1000 threshold", "ms": 2 }
    }
}
```

`status` is `ok`, `degraded` or `down`, on the report and on each check. The overall status is the
**worst** component — a report is only as good as its unhappiest part.

| Overall      | HTTP | Meaning                                                                     |
| ------------ | ---- | --------------------------------------------------------------------------- |
| `ok`         | 200  | Everything answered.                                                        |
| `degraded`   | 200  | Something is wrong, the app still works. **Not** an outage, so not a 503.   |
| `down`       | 503  | A component the app needs is gone.                                          |

`degraded` answering 200 is the point of having three words: the consumer is told something is
wrong without being told the app is unusable. A 503 would make every monitor in front of the app
declare an outage over a queue backlog.

## The checks

| Check      | What it actually does                                                                    |
| ---------- | ---------------------------------------------------------------------------------------- |
| `database` | `select 1`. Not `getPdo()` — that hands back a connection opened before the server left.  |
| `cache`    | Writes a token, reads it back, compares. A failed-over Redis accepts writes and returns null. |
| `storage`  | Writes a file, reads it back, deletes it — in a `finally`, so a probe cannot litter a bucket. |
| `queue`    | Pending depth **and** the age of the oldest waiting job.                                 |

The queue check watches two numbers because one of them lies: a backlog count alone misses a
worker that died on a quiet afternoon, leaving a queue that is nearly empty and entirely stuck.
Either threshold crossing is `degraded`, never `down`.

It only applies on the `database` queue driver. Redis and SQS keep their depth somewhere this
package has no business reaching into, so there it reports nothing rather than guessing.

Switch any of them off in `config/heartbeat.php` where it cannot mean anything.

## The deploy block

`/health/ops` shows the release, commit, branch and deploy time — **when there are any**. Apps
with no pipeline writing a `RELEASE` file simply do not get the card, because four rows of "not
recorded" read as a fault in the page rather than an absence in the deploy. Nothing to configure;
it reads `config('app.version')`, the `RELEASE` file, and `GIT_SHA` / `GIT_BRANCH` / `DEPLOYED_AT`,
and omits the key entirely when all of them are empty.

## Config

```php
'token' => env('HEARTBEAT_TOKEN'),
'api'   => ['enabled' => env('HEARTBEAT_API', true),  'path' => env('HEARTBEAT_API_PATH', 'health'),      'middleware' => []],
'web'   => ['enabled' => env('HEARTBEAT_PAGE', true), 'path' => env('HEARTBEAT_PAGE_PATH', 'health/ops'), 'middleware' => ['web', EnsureHeartbeatAccess::class], 'allow_authenticated' => true, 'guards' => []],
'checks'=> ['database' => true, 'cache' => true, 'storage' => true, 'queue' => true],
'queue' => ['pending_threshold' => 1000, 'stale_after_seconds' => 900],
'storage' => ['disk' => null],
'deploy'  => ['release_file' => 'RELEASE'],
```

## Why the page does not use `auth`

Laravel's `auth` middleware answers an unauthenticated visitor by redirecting to a route named
`login`. A Filament app has no such route, so the page died with `Route [login] not defined` in
any app without one — a diagnostic screen replaced by a 500 that had nothing to do with the
thing being diagnosed.

`EnsureHeartbeatAccess` decides access instead, and answers a stranger with **404**, never a
redirect: there is nowhere sensible to send someone who was not heading here, and an unauthorised
visitor should not learn there is anything at this address. It accepts either a signed-in user
(any configured guard, narrow it with `web.guards`) or the health token — the token being the only
way in when the login system is itself part of what has broken.

Set `allow_authenticated` to `false` to make the page token-only. If an app needs more than "signed
in" — a membership or active-account check — add that middleware to `web.middleware` alongside it.

## Tests

```bash
composer install && ./vendor/bin/pest
```
