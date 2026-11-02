-- Idempotent delivery, signed timestamps and a per-action forward timeout.
--
-- Deduplication is opt-in per room (`rooms.dedup_header`): the named header
-- carries the sender's delivery id (`Stripe-Event-Id`, `X-GitHub-Delivery`, …).
-- Turning it on for every room would silently drop repeats of integrations that
-- legitimately send the same body twice (a retry the sender never saw answered).
--
-- The key is stored on the first delivery only. A repeat cannot be inserted with
-- the same key — the partial unique index is what makes the check atomic, so two
-- concurrent deliveries of one event cannot both be treated as "the first one".
-- The repeat itself is still logged with `duplicate_of` pointing at the original:
-- the catcher's job is to show what arrived, and "the sender retried four times"
-- is exactly the kind of thing an operator needs to see.

ALTER TABLE rooms ADD COLUMN IF NOT EXISTS dedup_header VARCHAR(64);
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS require_timestamp BOOLEAN NOT NULL DEFAULT false;

ALTER TABLE incoming_requests ADD COLUMN IF NOT EXISTS dedup_key VARCHAR(200);
ALTER TABLE incoming_requests ADD COLUMN IF NOT EXISTS duplicate_of BIGINT
    REFERENCES incoming_requests (id) ON DELETE SET NULL;

CREATE UNIQUE INDEX IF NOT EXISTS incoming_requests_dedup_uniq
    ON incoming_requests (room_id, dedup_key)
    WHERE dedup_key IS NOT NULL;

-- Retention and a room delete remove rows in batches; without this index every
-- parent delete would sequentially scan the whole log to clear `duplicate_of`.
CREATE INDEX IF NOT EXISTS incoming_requests_duplicate_of_idx
    ON incoming_requests (duplicate_of)
    WHERE duplicate_of IS NOT NULL;
