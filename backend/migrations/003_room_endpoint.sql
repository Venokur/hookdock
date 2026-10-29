-- Every room owns the endpoint slug its webhook URLs advertise.
--
-- The value is part of the address users copy into third-party services, so it
-- lives with the room instead of being a hardcoded placeholder in the UI. The
-- receiver still accepts any endpoint under a room; this column only names the
-- room's recommended one. Existing rooms get a random `hook-xxxxxx` so no two
-- rooms advertise the same tail.

ALTER TABLE rooms ADD COLUMN IF NOT EXISTS endpoint VARCHAR(64);

UPDATE rooms
SET endpoint = 'hook-' || substr(md5(random()::text || id::text), 1, 6)
WHERE endpoint IS NULL;

-- Keeps inserts that omit the column (psql, future code) from failing NOT NULL.
ALTER TABLE rooms ALTER COLUMN endpoint SET DEFAULT ('hook-' || substr(md5(random()::text), 1, 6));
ALTER TABLE rooms ALTER COLUMN endpoint SET NOT NULL;
