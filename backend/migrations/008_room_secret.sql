-- Optional HMAC secret per room.
--
-- The catcher is public by design: anyone who knows the address can post to it.
-- A room can now require `X-Hookdock-Signature: sha256=<hex>`, which is an HMAC
-- of the raw body keyed by this secret, so a third party cannot inject requests
-- into the log or trigger the room's workflows.
--
-- The secret is stored as-is (HMAC needs the key, a hash cannot verify a
-- signature), but it is never returned by the API after it is generated: the
-- rooms endpoints expose only `has_secret`. Rotating it is an explicit action.

ALTER TABLE rooms ADD COLUMN IF NOT EXISTS secret TEXT;
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS secret_set_at TIMESTAMPTZ;
