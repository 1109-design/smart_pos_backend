# SmartPOS Realtime Sync — Production Runbook

How tills receive changes in under ~2s instead of waiting for the 10s
poll: `SyncTablesChanged` broadcast → Reverb socket → Flutter
`quickPullTables`. Polling stays as the fallback; a dead socket degrades,
it never blinds a till.

## 1. One-time server setup

```bash
# fresh credentials (never reuse dev keys)
php artisan tinker --execute 'echo bin2hex(random_bytes(16))."\n".bin2hex(random_bytes(32))."\n";'
```

`.env` (production):

```ini
REVERB_APP_ID=smartpos
REVERB_APP_KEY=<generated>
REVERB_APP_SECRET=<generated>
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http        # https/wss when behind TLS termination
QUEUE_CONNECTION=database
```

Install + start supervised processes (`deploy/supervisor/`):

```bash
sudo mkdir -p /var/log/smartpos
sudo cp deploy/supervisor/smartpos-reverb.conf deploy/supervisor/smartpos-queue.conf \
  /etc/supervisor/conf.d/
# edit the .conf paths if the backend lives outside /var/www/smartpos-backend
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status  # all RUNNING
```

Firewall: open the Reverb port to the tills' network only
(e.g. `ufw allow from 192.168.1.0/24 to any port 8080`).

TLS (recommended): terminate wss at Caddy/Nginx on 443 and set
`REVERB_SCHEME=https`; colocated same-host deploys need no Flutter
host override (the app derives it from the API URL).

## 2. App builds (CI)

`smart_pos/.github/workflows/ci.yml` already passes the defines on all
three build jobs. Set in the repo settings:

- Secret `SMARTPOS_REVERB_KEY` = production `REVERB_APP_KEY`
- Variables `SMARTPOS_REVERB_HOST` (only if not colocated),
  `SMARTPOS_REVERB_PORT` (e.g. `443`), `SMARTPOS_REVERB_SCHEME` (`wss`)

Builds without the secret still compile and run **poll-only** — safe
default, but verify the Sync Dashboard shows socket `connected` on
release tills or realtime silently isn't there.

## 3. Verify (do this on every deploy)

1. `ss -ltn | grep 8080` — Reverb listening.
2. Broadcast auth (device token): `POST /broadcasting/auth` with
   `socket_id` + `channel_name=private-business.<id>` → `{"auth":"…"}`;
   a foreign business id must 403.
3. E2E: subscribe over websocket, `POST /api/v1/sync/push` with a test
   record, expect `sync.tables_changed` with its table (<5s).
4. Two-till check: list screen open on till B, create on till A —
   expect appearance in <2s on LAN.

## 4. Operate / alert

| Signal | Meaning | Action |
|---|---|---|
| `supervisorctl status` reverb not RUNNING | no sockets | restart service; tills on poll fallback meanwhile |
| `failed_jobs` rows (broadcast) | events dying | inspect exception; common cause: stale `.env` in long-lived workers → restart queue daemons after every env change |
| `jobs` depth sustained > 50 | worker backlog | raise `numprocs` in `smartpos-queue.conf` |
| Till Sync Dashboard `disconnected` | socket down on that till | check LAN/firewall/key mismatch; poll still covers it |
| `sync.tables_changed` in `auto_resolved` diagnostics | healthy fan-out | — |

## 5. Known limits

- Payloads carry table names only; row data always comes via pull
  ("pull is authoritative" — by design, not a gap).
- Unknown table names in payloads are ignored client-side.
- `poll` (10s) + reconnect catch-up sync remain the backstop for
  missed events; `missedEventsRecovered` counter tracks gap recovery.
