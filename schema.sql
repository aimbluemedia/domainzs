-- domainzs — MySQL schema (idempotent: safe to re-import).

-- One users table, two roles:
--   superadmin → manages everything at /superadmin
--   member     → subscription user at /member
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username       VARCHAR(60)  NOT NULL,
    email          VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('superadmin','member') NOT NULL DEFAULT 'member',
    status         ENUM('active','disabled')   NOT NULL DEFAULT 'active',
    -- Subscription: paid features are gated on sub_status + sub_expires_at.
    sub_status     ENUM('none','trialing','active','canceled','expired') NOT NULL DEFAULT 'none',
    sub_plan_id    INT UNSIGNED NULL,
    sub_expires_at DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subscription plans shown on the public pricing section.
CREATE TABLE IF NOT EXISTS plans (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(60)  NOT NULL,
    slug          VARCHAR(60)  NOT NULL,
    price_cents   INT UNSIGNED NOT NULL DEFAULT 0,
    bill_interval ENUM('month','year') NOT NULL DEFAULT 'month',
    blurb         VARCHAR(190) NOT NULL DEFAULT '',
    features      TEXT         NULL,           -- one feature per line
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    sort          INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manual payment log (admin records payments when activating subscriptions).
CREATE TABLE IF NOT EXISTS payments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    plan_id      INT UNSIGNED NULL,
    amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    note         VARCHAR(190) NULL,
    paid_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_payments_user (user_id),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dropped domains pulled from the feed, filtered (9-char .com by default)
-- and rated by the scorer (+ optional AI pass).
CREATE TABLE IF NOT EXISTS drops (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain       VARCHAR(255) NOT NULL,
    sld          VARCHAR(190) NOT NULL,          -- name without the TLD
    tld          VARCHAR(20)  NOT NULL,
    len          TINYINT UNSIGNED NOT NULL,      -- SLD length
    dropped_date DATE         NOT NULL,
    score        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- heuristic 0-99
    score_notes  VARCHAR(500) NULL,              -- JSON array of reasons
    -- Availability re-verified via RDAP for the top-scored drops:
    --   'available' | 'registered' | 'unknown'
    availability VARCHAR(12)  NOT NULL DEFAULT 'unknown',
    -- Optional AI pass (Claude) on the top-scored drops.
    ai_rating    TINYINT UNSIGNED NULL,          -- 0-99
    ai_comment   VARCHAR(300) NULL,
    est_value    INT UNSIGNED NULL,              -- estimated resale value, USD
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drops_domain (domain),
    KEY ix_drops_date_score (dropped_date, score),
    KEY ix_drops_score (score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Members can favorite drops.
CREATE TABLE IF NOT EXISTS favorites (
    user_id    INT UNSIGNED NOT NULL,
    drop_id    INT UNSIGNED NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, drop_id),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_drop FOREIGN KEY (drop_id) REFERENCES drops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Domains listed for sale on the public site.
CREATE TABLE IF NOT EXISTS listings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain      VARCHAR(255) NOT NULL,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    headline    VARCHAR(190) NULL,
    description VARCHAR(500) NULL,
    score       TINYINT UNSIGNED NULL,           -- carried over from the drop
    status      ENUM('active','sold','hidden') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_listings_domain (domain),
    KEY ix_listings_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Offers / inquiries from the public "make an offer" form.
CREATE TABLE IF NOT EXISTS offers (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id   INT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(190) NOT NULL,
    amount_cents INT UNSIGNED NULL,
    message      VARCHAR(1000) NULL,
    is_read      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_offers_listing (listing_id),
    CONSTRAINT fk_offers_listing FOREIGN KEY (listing_id)
        REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Site settings (key/value) — editable in /superadmin/settings.php.
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(60)  NOT NULL,
    sval VARCHAR(500) NOT NULL,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
