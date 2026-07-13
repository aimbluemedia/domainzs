# 🌐 domainzs

A password-protected web app that watches **domain names** for you:

- **Portfolio** — track the domains you own. Expiry dates, registrar, and
  status are pulled automatically via **RDAP** (the free successor to WHOIS —
  no API key needed), and you get renewal-reminder emails at **30 / 7 / 1**
  day(s) before a domain expires.
- **Watchlist** — track the domains you *want*. domainzs checks them on every
  scan and emails you the moment one becomes **available** or enters
  **pending delete** (about to drop).

Built with **PHP 8** + **MySQL/MariaDB** — no framework, no build step.

---

## How it works

1. Add domains you **own** to the Portfolio and domains you **want** to the
   Watchlist (paste anything — `Example.COM`, a full URL, `www.` prefix — it
   gets normalised).
2. Each domain is looked up via the **rdap.org** bootstrap redirector, which
   forwards the query to the authoritative registry. A `404` from RDAP means
   the domain isn't registered — i.e. it's available.
3. The dashboard shows expiry countdowns, registrar info, and live
   availability, sorted by what needs attention first.
4. A CLI checker runs on **cron**, re-checks stale domains, and sends one
   digest email per run covering new alerts (renewals due, domains dropped).
5. Every alert fires **exactly once** per event — dedupe is handled in the
   database, so cron can run as often as you like.

> **Mock mode:** set `rdap.mock = true` in `config.php` and the app runs on
> realistic sample data with no network calls, so you can explore every
> feature offline. The dashboard shows a banner while mock mode is on.

---

## Requirements

- PHP 8.1+ with `pdo_mysql` and `curl` extensions
- MySQL 5.7+ / MariaDB 10.3+

No API keys required — RDAP is free and credential-less.

---

## Setup

```bash
# 1. Configure
cp config.sample.php config.php
#   edit config.php — set DB credentials (and email settings if you want alerts)

# 2. Create the database
mysql -u root -e "CREATE DATABASE domainzs CHARACTER SET utf8mb4;"

# 3. Import schema + create your login (username, password, [email])
php bin/install.php admin 'your-strong-password' you@example.com

# 4. Serve the app
php -S 127.0.0.1:8000
#   then open http://127.0.0.1:8000/login.php
```

### Deploying to a subdomain (e.g. domainzs.com on shared hosting)

The whole app runs from a **single folder** — the subdomain's document root.

1. Upload everything in this repo into the subdomain's document root folder.
2. The included `.htaccess` files keep `config.php`, `schema.sql`, and the
   `src/` and `bin/` directories private — only the app pages are served.
3. Create `config.php` and run the installer (or import `schema.sql` via
   phpMyAdmin and insert your user by hand).

---

## Automated checking (cron)

Run the CLI checker on a schedule so drops and renewals are caught even while
you're away:

```cron
0 * * * *  php /path/to/domainzs/bin/check.php >> /var/log/domainzs.log 2>&1
```

- Without flags it re-checks only **stale** domains (older than
  `rdap.recheck_hours`, default 12h) — polite to the registries.
- `php bin/check.php --force` re-checks everything immediately (same as the
  dashboard's **Check all now** button).

Enable email in `config.php` (`mail.enabled = true`, set `mail.to`) to receive
a digest whenever new alerts fire. Email uses PHP's `mail()`; for Gmail/SMTP,
configure your server's mail transport or an SMTP relay.

---

## Project layout

```
.htaccess              Web-root rules (protects config/schema/code)
index.php              Dashboard — stats, expiring soon, watchlist status
portfolio.php          Manage domains you own
watchlist.php          Manage domains you want
check.php              "Check all now" action
login.php logout.php   Authentication
assets/style.css
config.sample.php      Configuration template (copy to config.php)
config.php             Your real config — git-ignored, blocked from web
schema.sql             MySQL schema (idempotent, blocked from web)
src/                   Application code — included by PHP, blocked from web
  .htaccess            Deny-all
  bootstrap.php        Config, autoloader, session, DB
  helpers.php  layout.php
  Database.php  Auth.php
  RdapClient.php       RDAP lookups (+ DNS fallback + mock mode)
  DomainChecker.php    Scan engine — refresh, history, alert detection
  Notifier.php         Email digests
bin/                   CLI scripts — blocked from web
  .htaccess            Deny-all
  install.php          One-time setup (schema + your login)
  check.php            Cron checker
```

---

## Security notes

- All pages except login require an authenticated session.
- Passwords are stored with `password_hash()` (bcrypt).
- Forms are CSRF-protected; sessions use `HttpOnly`/`SameSite` cookies and are
  regenerated on login.
- `config.php` is git-ignored so credentials are never committed.
- There is no public signup — accounts are created only via `bin/install.php`.
