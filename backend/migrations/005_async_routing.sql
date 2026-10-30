-- Asynchronous routing: the receiver stores the webhook and returns 202 at once,
-- then the `worker` service picks matching workflows out of this table.
--
-- `workflow_runs` doubles as the journal it already was and the queue: existing
-- readers (request detail, list counters, `last_run_status`) keep working, and the
-- queue is just two more statuses plus a partial index. No second table to keep
-- in sync with the journal.

ALTER TABLE workflow_runs
    ADD COLUMN IF NOT EXISTS attempts     INT         NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS available_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS locked_at    TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS locked_by    TEXT,
    ADD COLUMN IF NOT EXISTS updated_at   TIMESTAMPTZ NOT NULL DEFAULT now();

-- Only claimable rows are indexed: finished runs never slow a claim down.
CREATE INDEX IF NOT EXISTS workflow_runs_queue_idx
    ON workflow_runs (available_at, id)
    WHERE status IN ('pending', 'running');

CREATE INDEX IF NOT EXISTS workflow_runs_workflow_status_idx
    ON workflow_runs (workflow_id, status);

-- One row per action per attempt. A retry skips the actions that already
-- succeeded, so a partially failed run never re-sends a webhook it already sent.
CREATE TABLE IF NOT EXISTS workflow_run_steps (
    id              BIGSERIAL PRIMARY KEY,
    run_id          BIGINT      NOT NULL REFERENCES workflow_runs (id) ON DELETE CASCADE,
    attempt         INT         NOT NULL,
    action_index    INT         NOT NULL,
    type            VARCHAR(32) NOT NULL,
    status          VARCHAR(16) NOT NULL,
    message         TEXT,
    response_status INT,
    response_body   TEXT,
    duration_ms     INT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS workflow_run_steps_run_idx ON workflow_run_steps (run_id, id);
