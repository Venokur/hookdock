-- Scheduled workflows: a rule can fire on a cron schedule instead of a webhook.
--
-- A scheduled fire is a run **without a request**. `workflow_runs.request_id` was
-- NOT NULL, which encoded "a run always belongs to a stored delivery" — true until
-- the product could start a rule by itself. Making it nullable is honest; the
-- alternative (a synthetic `incoming_requests` row per fire) would put a delivery
-- that never arrived into the log, and every reader would need a filter to hide it
-- again.
--
-- `room_id` therefore moves onto the run: room scoping and the journal stop
-- depending on a join to `incoming_requests`, which a scheduled run does not have.
-- It is fixed at creation, so a run stays attributable to its room even when the
-- request it came from is gone.
--
-- `schedule_next_at` is the only queue the scheduler needs: a partial index over
-- due rules means an idle worker checks one index and nothing else.

ALTER TABLE workflows
    ADD COLUMN IF NOT EXISTS schedule_cron    VARCHAR(120),
    ADD COLUMN IF NOT EXISTS schedule_next_at TIMESTAMPTZ;

ALTER TABLE workflow_runs
    ADD COLUMN IF NOT EXISTS trigger_type VARCHAR(16) NOT NULL DEFAULT 'webhook',
    ADD COLUMN IF NOT EXISTS room_id      BIGINT;

-- Every existing run has a request (the column was NOT NULL), so this backfill is
-- complete and the NOT NULL below cannot fail on a real installation.
UPDATE workflow_runs wr
   SET room_id = i.room_id
  FROM incoming_requests i
 WHERE i.id = wr.request_id
   AND wr.room_id IS NULL;

ALTER TABLE workflow_runs ALTER COLUMN room_id SET NOT NULL;
ALTER TABLE workflow_runs ALTER COLUMN request_id DROP NOT NULL;

ALTER TABLE workflow_runs
    ADD CONSTRAINT workflow_runs_room_fk
    FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS workflow_runs_room_created_idx
    ON workflow_runs (room_id, created_at DESC);

-- Only rules that are actually due are indexed: the scheduler scans this and
-- nothing else, so a disabled or webhook-only rule costs it nothing.
CREATE INDEX IF NOT EXISTS workflows_schedule_due_idx
    ON workflows (schedule_next_at)
    WHERE enabled AND schedule_cron IS NOT NULL;

-- A version has to restore the trigger too, otherwise a rollback would silently
-- turn a scheduled rule back into a webhook one.
ALTER TABLE workflow_versions
    ADD COLUMN IF NOT EXISTS schedule_cron VARCHAR(120);
