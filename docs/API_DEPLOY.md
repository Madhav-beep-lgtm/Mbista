# Running the API on cPanel

The read-only JSON API (`index.js`) under cPanel's **Setup Node.js App**, which
runs Node behind Passenger and Apache.

Everything here is done once. After that a `git push` is the whole deploy:
`deploy/tasks.sh` touches `tmp/restart.txt` and Passenger picks the new code up
on the next request.

---

## Before you start

**Check the plan actually has it.** cPanel → Software. If there is no *Setup
Node.js App* icon, the host has not enabled Passenger and none of this will
work — the PHP application is unaffected either way, and the PWA needs no API
at all.

**Decide the URL.** A subdomain is cleaner than a path:

| | |
|---|---|
| Subdomain | `api.mbca.com.np` — its own SSL certificate, no chance of colliding with a PHP route |
| Path | `mbca.com.np/nodeapi` — one certificate, but Apache has to be told the path is not PHP |

Create the subdomain first (cPanel → Domains → Create A New Domain) and let
AutoSSL issue for it. **The API must be reached over HTTPS**: a Bearer token
over plain HTTP is a password read by anyone on the path.

---

## 1. Create the application

cPanel → **Setup Node.js App** → *Create Application*:

| Field | Value |
|---|---|
| Node.js version | 18 or newer |
| Application mode | Production |
| Application root | `repositories/Mbista` |
| Application URL | the subdomain from above |
| Application startup file | `index.js` |

**Application root is the repository itself**, on purpose: the code Passenger
runs is then the code `git pull` updates, and there is no second copy to
forget. `deploy/tasks.sh` already looks there for `index.js` and `node_modules`
before it signals a restart.

## 2. Environment variables

Still on that screen, add these. They are separate from the PHP `.env` — the
Node process does not inherit it — except that `index.js` also reads
`repositories/Mbista/.env` if one is there, so either place works.

| Variable | Value |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_NAME` | `mbcacomn_mbista` |
| `DB_USER` | the same user the PHP app uses |
| `DB_PASS` | that user's password |
| `JWT_SECRET` | **generate a fresh one, see below** |
| `API_CORS_ORIGINS` | only if a browser client on another domain calls this |
| `API_TRUST_PROXY` | `1` (the default; raise it only if something sits in front of Apache) |

Generate the secret on the server, and do not reuse the development one:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

Anyone holding `JWT_SECRET` can mint a token for any user of any company. It
belongs in the cPanel variable list or in `.env` — never in the repository.
`.env` and `*.env` are already git-ignored.

The app **refuses to start** without a 24-character secret. That is deliberate:
falling back to no authentication is how an open API reaches production
believing itself protected.

## 3. Install the dependencies

The *Run NPM Install* button on the same screen, or over SSH:

```bash
source ~/nodevenv/repositories/Mbista/18/bin/activate
cd ~/repositories/Mbista
npm install --omit=dev
```

The `source` line matters — cPanel gives each app its own Node environment, and
a plain `npm install` may use a different Node than the one Passenger runs.

## 4. Start it, and check

Press **Restart**, then from your laptop:

```bash
curl -i https://api.mbca.com.np/api/health
# expect: 200 {"status":"ok","database":"up","time":"…"}
```

**Start with that one.** It needs no token, and it is the only check that
distinguishes the three ways this fails, which look identical in a browser —
all of them a blank page:

| what you get | what it means |
|---|---|
| `200` and JSON | Node is running and can read the database |
| `503 {"database":"down"}` | Node is running; `DB_*` is wrong. The reason is in the stderr log, never in the reply |
| `200` with an **empty body**, `Content-Type: text/html` | Node is NOT running. Apache is serving the subdomain's empty directory — Passenger was never started, or `npm install` was never run |
| `502` | the startup file threw; see the log |

That third row is the one that wastes an afternoon, because a zero-byte `200`
looks like a working server returning nothing.

```bash
curl -i https://api.mbca.com.np/api/users
# expect: 401, "Log in at /api/auth/login…"

curl -s -X POST https://api.mbca.com.np/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","password":"…"}'
# expect: a token

curl -s https://api.mbca.com.np/api/trial-balance \
  -H "Authorization: Bearer <token>"
```

`401` on the first call is the API working. A `200` with data would mean the
build predates the authentication and must not stay up.

---

## Afterwards

**Deploys.** `git push`, then the five-minute cron runs `deploy/auto-deploy.sh`,
which runs `tasks.sh`, which touches `tmp/restart.txt`. Nothing else to do. To
hurry it:

```bash
/bin/bash ~/repositories/Mbista/deploy/auto-deploy.sh
```

**Logs.** Passenger writes to `~/logs/` or the app's own stderr log, shown in
the cPanel screen. The API logs SQL errors there and returns only a generic
message to the caller, so the log is the only place a real reason appears.

**If every request 502s.** Almost always the startup file threw — a missing
`JWT_SECRET`, a wrong `DB_*`, or dependencies never installed. The stderr log
says which; all four failures print a plain sentence.

**If logins start failing for everyone at once.** The throttle is five failures
per address per fifteen minutes, and it keys on `req.ip`. Behind Apache every
request looks like it came from Apache unless `trust proxy` is right, which is
what `API_TRUST_PROXY` sets. It defaults to 1, which is correct for a normal
cPanel account; a value that is too high lets a caller forge `X-Forwarded-For`
and give itself a fresh bucket per guess.

---

## What this does not do

It **cannot write**. Non-GET is refused at the door, on purpose: every rule that
makes a figure correct — company scoping, fiscal-year locks, the settlement
identity, how a kaligad receipt values metal that was never issued — lives in
the PHP application, and a second stack that could write would be a second
implementation of all of it, disagreeing quietly.

Permissions are **coarse**. The token carries `role` and `company_id`, so any
active user of a company can read that company's whole trial balance. The PHP
side has `user_can_do()` with far finer grain, and it is not ported.

And it is **not needed for the phone app**. The PWA installs from the browser,
talks to the PHP application directly, and inherits its login, its roles and its
company scoping instead of reimplementing them. This API is for something else
calling in — an integration, a report tool, a separate client.
