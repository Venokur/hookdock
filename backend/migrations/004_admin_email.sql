-- Renames the seeded administrator to the hookdock domain.
--
-- 001_init.sql is already applied on existing volumes, so editing it would only
-- fix fresh installs. This migration moves the account on a live database too
-- and stays a no-op once the new address exists (or if it was taken by hand).

UPDATE users
SET email = 'admin@hookdock.local'
WHERE lower(email) = 'admin@l.loc'
  AND NOT EXISTS (
      SELECT 1 FROM users WHERE lower(email) = 'admin@hookdock.local'
  );
