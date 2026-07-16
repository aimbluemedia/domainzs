# 🌐 domainzs

Dropped-domain hunting, productized. domainzs pulls the daily list of
**dropped (deleted) domains** from a feed, keeps only the ones you hunt —
**9-character .coms** by default — and **rates every name 0–99** for
brandability and resale value. The best get an optional **AI second opinion**
and a live **RDAP availability check**.

Around that engine is a complete three-area site:

- **Public website** — landing page, pricing, and a **domains-for-sale
  marketplace** with a "make an offer" flow.
- **Member area** (`/member`) — the daily rated **Drop Board**. Free accounts
  see the top 3 per day; **paid subscribers** see everything, plus favorites,
  filters, AI ratings, and value estimates.
- **Superadmin** (`/superadmin`) — fetch & rate on demand, manage listings,
  read offers, manage members & subscriptions (with payment log), edit plans,
  and control every setting from the UI.

Built with **PHP 8** + **MySQL/MariaDB** — no framework, no build step.

---

## How it works

1. `bin/fetch.php` (cron or the admin **Fetch now** button) downloads the drop
   list for the day.
2. The filter keeps only the configured TLDs and **exact name length**
   (default: 9-char `.com`) — both editable in **Settings**.
3. Every surviving name is scored by the heuristic engine: vowel balance,
   real-word detection ("cloudforge" = cloud + forge), startup-style suffixes,
   consonant clusters, digits/hyphens, rare letters… each score comes with
   plain-English reasons.
4. The day's top names are re-verified for availability. With the
   **name.com API** configured (Settings → name.com API), this is a bulk
   check — 50 domains per call — that also returns the **real registration
   price** shown on the drop board. Without it, the app falls back to free
   per-domain **RDAP** lookups (no prices). The best names are optionally
   sent to **Claude** for an AI rating, one-line comment, and resale value
   estimate.
5. Members browse the board; you register the keepers and list them on the
   marketplace with one click. Offers land in your admin inbox + email.

> **Mock mode:** out of the box the feed provider is `mock` — deterministic
> sample drops, zero network calls — so every screen works immediately.
> Point it at a real feed in `/superadmin/settings.php` when you're ready.

---

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl` (and `zip` for zipped feeds)
- MySQL 5.7+ / MariaDB 10.3+
- A dropped-domains feed URL (see below) — optional until you go live
- (Optional) name.com API token for bulk availability checks + live
  registration prices — https://www.name.com/account/settings/api
- (Optional) Moz API credentials for Domain Authority + linking-domain counts
  on the top drops — https://moz.com/products/api (free tier)
- (Optional) Anthropic API key for AI ratings — https://console.anthropic.com/

---

## Setup

```bash
# 1. Configure
cp config.sample.php config.php
#   edit config.php — set DB credentials

# 2. Create the database
mysql -u root -e "CREATE DATABASE domainzs CHARACTER SET utf8mb4;"

# 3. Import schema + create your superadmin login
php bin/install.php admin 'your-strong-password' you@example.com

# 4. Serve the app
php -S 127.0.0.1:8000
#   homepage:  http://127.0.0.1:8000/
#   admin:     http://127.0.0.1:8000/login.php  (your installer login)

# 5. Pull your first batch of drops
php bin/fetch.php
```

### Deploying to Hostinger / shared hosting

The whole app runs from a **single folder** — the domain's document root.

1. Deploy the repo into the document root (hPanel → Git, branch `main`).
2. The included `.htaccess` files keep `config.php`, `schema.sql`, and the
   `src/` and `bin/` directories private — only the app pages are served.
3. Create `config.php`, then run the installer via SSH — or import
   `schema.sql` in phpMyAdmin and insert your superadmin row by hand.
4. Add the cron job in hPanel (see below).

---

## The drop feed

**Free (recommended to start): WhoisFreaks' free daily list.** WhoisFreaks
publishes ~10,000 dropped/expired domains every day at 03:00 UTC in a public
GitHub repo — no key, no subscription. Set provider to **WhoisFreaks FREE
daily list** and you're done. The fetcher tries the dated file, the archive
copy, then falls back to the always-current "latest" file.

**Full coverage: WhoisFreaks' paid API** (~400k domains/day). Get an API key
from the billing dashboard, set provider to **WhoisFreaks paid API**, and
paste the key. If your dashboard shows a different download link than the
built-in default, paste it into the URL-override field with `{date}` and
`{apiKey}` placeholders.

**Alternative: any custom URL.** Set provider to **URL feed** and paste a URL
that returns one domain per line (`.txt`, `.csv`, or a `.zip`/`.gz` of one).
Date placeholders in the URL are replaced with the day being fetched:

| Placeholder | Becomes | Used by |
|---|---|---|
| `{date}` | `2026-07-14` | most plain feeds |
| `{date_ymd}` | `20260714` | compact-date feeds |
| `{date_b64}` | `MjAyNi0wNy0xNC56aXA=` (base64 of `2026-07-14.zip`) | WhoisDS download links |

This works with WhoisDS's downloadable lists and most paid drop feeds
(DropCatch, ExpiredDomains exports, registrar drop lists…). A day's list is
published **after** the registry finishes deleting for that day, so schedule
cron accordingly (see below) — and note that each provider defines the date
slightly differently (deleted *on* that date vs. published that morning).

Until then, the built-in **mock feed** generates realistic sample drops so you
can explore and demo everything offline.

---

## Automated fetching (cron)

```cron
30 6 * * *  php /path/to/domainzs/bin/fetch.php >> /var/log/domainzs.log 2>&1
```

**No SSH? Use the URL cron instead.** Settings → Automation shows a
secret-key URL (`https://your-domain.com/daily-run.php?key=…`); point any URL
cron (hPanel's wget job, UptimeRobot, cron-job.org) at it once a day. It runs
the same pipeline (the free RDAP availability fallback is command-cron only, to
keep the web request fast — name.com availability still works either way). The
endpoint is `daily-run.php` rather than `cron.php` because many hosts block
direct web access to files named `cron.php`.

`bin/fetch.php` (and the URL cron) also generate that day's **Daily Recap** automatically once
the batch is in (an AI deep-dive on the best names — top pick, ranked top 10,
sleeper, build-a-business angle, resale ranges — shown at
`/superadmin/dailyrecap.php`). To regenerate on its own schedule, or run it
separately:

```cron
0 7 * * *  php /path/to/domainzs/bin/recap.php >> /var/log/domainzs.log 2>&1
```

The recap uses Claude when an Anthropic API key is set (Settings → AI); with no
key it builds a heuristic recap from the scores so the page always works. Add
your background under "Personalise the recap" on the Daily Recap page and the
AI tailors the build-a-business pick to you.

**Email it every morning:** enable email and tick *"Email the Daily Recap every
morning"* in Settings → Email. The cron sends one HTML recap per day (top pick,
top 10, sleeper, build-a-business, verdict) to your `mail.to` address — deduped
so an hourly cron still sends a single morning email. Use *Send test email* on
the Daily Recap page to preview it.

Enable email in **Settings** to get a digest whenever new drops land, plus an
instant email for every marketplace offer.

---

## Subscriptions & payments

Plans (Free / Pro by default) are managed in `/superadmin/pricing.php` and
shown on the public homepage. Payments are **manual by design** — collect via
PayPal, a Stripe payment link, bank transfer, whatever — then activate the
member in `/superadmin/members.php` (pick plan + months). The payment is
logged, access expires automatically at the paid-through date, and lifetime
revenue is totalled for you. No payment-gateway code to maintain, PCI scope
of zero; wire in Stripe later if volume demands it.

**Gating:** free members see the top 3 drops per day. Pro members
(`sub_status` active/trialing and not past `sub_expires_at`) get the full
board, favorites, filters, AI columns, and availability checks.

---

## Project layout

```
.htaccess               Web-root rules (protects config/schema/code)
index.php               Public homepage: hero, features, marketplace teaser, pricing
domains.php             Public marketplace + "make an offer" form
signup.php login.php logout.php
member/                 Member area (login required)
  index.php             Dashboard — latest batch stats + top drops
  drops.php             The Drop Board (full board = Pro)
  favorites.php         Starred names
  account.php           Plan info + password change
superadmin/             Admin console (superadmin role required)
  index.php             KPIs, latest batch, recent offers
  drops.php             All drops · Fetch & rate now · one-click "List for sale"
  listings.php          Marketplace CRUD
  offers.php            Offer inbox
  members.php           Users, subscription activation, payment log
  pricing.php           Plan editor
  settings.php          Feed/filter, AI, email, homepage copy
assets/style.css
config.sample.php       Configuration template (copy to config.php)
schema.sql              MySQL schema (idempotent, blocked from web)
src/                    Application code — blocked from web
  bootstrap.php helpers.php layout.php
  Database.php Auth.php
  DropsClient.php       Feed download: mock / URL (txt, csv, zip)
  Scorer.php            Heuristic 0–99 rating with reasons
  DropEngine.php        Fetch → filter → score → verify → AI pipeline
  NameComClient.php     name.com API: bulk availability + registration prices
  RdapClient.php        Availability checks (free RDAP fallback)
  AiRater.php           Claude ratings (+ heuristic mock fallback)
  Notifier.php          Offer alerts + fetch digests
bin/                    CLI scripts — blocked from web
  install.php           One-time setup (schema + superadmin + plans)
  fetch.php             Cron fetcher
```

---

## Security notes

- Member and admin areas require an authenticated session; superadmin pages
  check the role on every request.
- Passwords are stored with `password_hash()` (bcrypt).
- All forms are CSRF-protected; sessions use `HttpOnly`/`SameSite` cookies and
  are regenerated on login.
- `config.php` is git-ignored so credentials are never committed.
