# sadorect/ops-agent

The agent half of the [Sadorect Ops](../../README.md) control plane. Drop it into any
Laravel 11/12 app to report health, metrics, logs and errors to the hub, and to execute
**allow-listed** remote commands the hub dispatches.

> This package lives here for development alongside the hub. In production it is published
> as a standalone Composer package and required into each managed app.

## Install

Published as a Git VCS repo. In the managed app:

```bash
composer config repositories.ops-agent vcs https://github.com/sadorect/ops-agent
composer require sadorect/ops-agent:dev-main
php artisan vendor:publish --tag=ops-agent-config
```

Register the app in the hub (Fleet → Applications), rotate its credentials, and copy them
into the managed app's `.env`:

```dotenv
OPS_HUB_URL=https://hub.sadorect.com
OPS_APP_SLUG=sadorect-app
OPS_APP_TOKEN=<token shown once at the hub>
OPS_HMAC_SECRET=<secret shown once at the hub>
```

To forward logs, add the `ops` channel to your stack in `config/logging.php` (the agent
registers the channel automatically — you only need to reference it):

```dotenv
LOG_STACK=single,ops
```

## What it does

| Capability | Mechanism | Hub endpoint |
|---|---|---|
| Health heartbeat | `ops:heartbeat`, scheduled every minute | `POST /api/ingest/heartbeat` |
| Log forwarding | buffered Monolog handler (`ops` channel), flushed on shutdown | `POST /api/ingest/logs` |
| Error grouping | `reportable` hook, fingerprints exceptions | `POST /api/ingest/errors` |
| Remote commands | `ops:poll`, scheduled every minute | `GET /api/agent/commands`, `POST /api/agent/commands/{id}/result` |

The package registers its scheduled commands automatically — just ensure the app's
scheduler runs (`php artisan schedule:work` or a cron entry).

## Security

- Every request carries `X-Ops-App`, `X-Ops-Token`, `X-Ops-Timestamp`, and
  `X-Ops-Signature = HMAC-SHA256("{timestamp}.{rawBody}", secret)` — matching the hub's
  `VerifyAgentSignature` middleware, with timestamped replay protection.
- Dispatched commands are **verified twice** before running: the hub's HMAC signature over
  `{id}.{command}.{expires_at}` is checked, the expiry window is enforced, and the command
  must appear in this agent's own `commands.allowlist`. There is no raw shell path.

## Configuration

See [`config/ops-agent.php`](config/ops-agent.php). Highlights: `enabled` master switch,
`logs.level` / `logs.batch_size`, `commands.allowlist` / `commands.timeout`, and the
heartbeat's queue connection/name for queue-depth reporting.
