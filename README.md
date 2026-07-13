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
4. The day's top names are re-verified via **RDAP** (free, no API key —
   a 404 means the domain is still available) and optionally sent to
   **Claude** for an AI rating, one-line comment, and resale value estimate.
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

Set **provider = URL feed** in `/superadmin/settings.php` and paste a URL that
returns one domain per line (`.txt`, `.csv`, or a `.zip` of one). `{date}` in
the URL is replaced with the day being fetched (YYYY-MM-DD). This works with
WhoisDS's downloadable deleted-domain lists and most paid drop feeds
(DropCatch, ExpiredDomains exports, registrar drop lists…).

Until then, the built-in **mock feed** generates realistic sample drops so you
can explore and demo everything offline.

---

## Automated fetching (cron)

```cron
30 6 * * *  php /path/to/domainzs/bin/fetch.php >> /var/log/domainzs.log 2>&1
```

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
  RdapClient.php        Availability checks (free RDAP)
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
