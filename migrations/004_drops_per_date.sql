-- Make drops unique per (domain, date) instead of globally per domain, so each
-- day's fetch is its own batch and the board advances daily. Safe to run once.
--
-- (If the ADD fails because you have older duplicate rows, the SELECT below
--  shows them; but a fresh drops table won't.)

ALTER TABLE drops DROP INDEX uq_drops_domain;
ALTER TABLE drops ADD UNIQUE KEY uq_drops_domain_date (domain, dropped_date);
