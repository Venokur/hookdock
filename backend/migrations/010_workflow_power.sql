-- Three things the routing engine needs that the schema did not have.
--
-- 1. `conditions` becomes a tree: `{"mode": "all"|"any", "rules": [...]}` where a
--    rule is either a condition or another group. Strict AND over a flat list
--    cannot express "(source = stripe) AND (type = paid OR type = refunded)",
--    which is the shape real routing rules take. Existing rows are a plain list,
--    which the engine reads as `all`, so they are normalized once here instead of
--    leaving two shapes to test forever.
--
-- 2. `priority` + `stop_processing` make a set of rules behave like routing:
--    without them every matching rule runs, in creation order, and there is no way
--    to say "the first match wins" or "this rule is more specific". Ordering by a
--    column beats depending on which rule happened to be created first.
--
-- 3. `worker_heartbeats` is what turns `/api/health` from a hard-coded `ok` into a
--    real answer: a queue that is not moving because the worker died looks exactly
--    like an idle installation otherwise.

ALTER TABLE workflows
    ADD COLUMN IF NOT EXISTS priority        INT     NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS stop_processing BOOLEAN NOT NULL DEFAULT false;

UPDATE workflows
   SET conditions = jsonb_build_object('mode', 'all', 'rules', conditions)
 WHERE jsonb_typeof(conditions) = 'array';

CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_id  TEXT PRIMARY KEY,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    beat_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    claimed    INT         NOT NULL DEFAULT 0,
    note       TEXT
);

-- Health asks "was any worker alive recently", which is a range scan on beat_at.
CREATE INDEX IF NOT EXISTS worker_heartbeats_beat_idx
    ON worker_heartbeats (beat_at DESC);
