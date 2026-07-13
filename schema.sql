-- domainzs — MySQL schema (idempotent: safe to re-import).

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username      VARCHAR(60)     NOT NULL,
    email         VARCHAR(190)    NULL,
    password_hash VARCHAR(255)    NOT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per domain, in one of two lists:
--   kind = 'portfolio' → a domain you own (renewal reminders)
--   kind = 'watchlist' → a domain you want (availability / drop alerts)
CREATE TABLE IF NOT EXISTS domains (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    domain          VARCHAR(255)   NOT NULL,
    kind            ENUM('portfolio','watchlist') NOT NULL DEFAULT 'portfolio',
    -- Latest known registration status:
    --   'registered' | 'available' | 'pending_delete' | 'unknown'
    status          VARCHAR(20)    NOT NULL DEFAULT 'unknown',
    registrar       VARCHAR(190)   NULL,
    registered_at   DATETIME       NULL,
    expires_at      DATETIME       NULL,
    -- Raw RDAP status codes, comma-separated (e.g. "client transfer prohibited").
    rdap_status     VARCHAR(500)   NULL,
    -- Portfolio bookkeeping.
    renewal_cost    DECIMAL(8,2)   NULL,
    auto_renew      TINYINT(1)     NOT NULL DEFAULT 0,
    notes           VARCHAR(500)   NULL,
    last_checked_at DATETIME       NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_domains_domain (domain),
    KEY ix_domains_kind (kind),
    KEY ix_domains_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check history — one row per lookup, so the dashboard can show what changed.
CREATE TABLE IF NOT EXISTS domain_checks (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id  INT UNSIGNED NOT NULL,
    status     VARCHAR(20)  NOT NULL,
    expires_at DATETIME     NULL,
    checked_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_checks_domain (domain_id, checked_at),
    CONSTRAINT fk_checks_domain FOREIGN KEY (domain_id)
        REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alerts already sent, so each event emails exactly once.
--   kind = 'expiry_30' | 'expiry_7' | 'expiry_1' | 'available' | 'pending_delete'
CREATE TABLE IF NOT EXISTS alerts (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id INT UNSIGNED NOT NULL,
    kind      VARCHAR(30)  NOT NULL,
    -- Disambiguates repeat events (e.g. the expiry date the reminder was for).
    ref       VARCHAR(40)  NOT NULL DEFAULT '',
    sent_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alerts_once (domain_id, kind, ref),
    CONSTRAINT fk_alerts_domain FOREIGN KEY (domain_id)
        REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Site settings (key/value).
CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(60)  NOT NULL,
    sval VARCHAR(500) NOT NULL,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
