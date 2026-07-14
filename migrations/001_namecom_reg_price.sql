-- Migration for installs created before the name.com integration.
-- Adds the live registration price column to drops.
-- (Fresh installs get this from schema.sql — running it twice is harmless
--  on MariaDB thanks to IF NOT EXISTS; plain MySQL will just error that the
--  column exists, which you can ignore.)

ALTER TABLE drops ADD COLUMN IF NOT EXISTS reg_price DECIMAL(8,2) NULL AFTER availability;
