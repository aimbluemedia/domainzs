-- Migration for installs created before the Moz (Domain Authority) integration.
-- Adds SEO metric columns to drops. (Fresh installs get these from schema.sql;
-- MariaDB's IF NOT EXISTS makes re-runs harmless.)

ALTER TABLE drops ADD COLUMN IF NOT EXISTS moz_da    TINYINT UNSIGNED NULL AFTER reg_price;
ALTER TABLE drops ADD COLUMN IF NOT EXISTS moz_pa    TINYINT UNSIGNED NULL AFTER moz_da;
ALTER TABLE drops ADD COLUMN IF NOT EXISTS moz_links INT UNSIGNED NULL AFTER moz_pa;
