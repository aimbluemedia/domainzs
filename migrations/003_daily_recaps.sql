-- Migration for installs created before the Daily Recap feature.
-- (Fresh installs get this table from schema.sql.)

CREATE TABLE IF NOT EXISTS daily_recaps (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recap_date DATE         NOT NULL,
    body       MEDIUMTEXT   NOT NULL,
    drop_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_ai      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recap_date (recap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
