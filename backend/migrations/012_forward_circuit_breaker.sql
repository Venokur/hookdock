-- Circuit breaker per forward target.
--
-- Why a breaker at all: `UrlGuard` decides whether an address may be called, not
-- whether it is working. A dead target therefore turns every delivery into
-- ROUTING_MAX_ATTEMPTS failed calls and then a failed run; after
-- WORKFLOW_FAILURE_THRESHOLD of those the *workflow* is paused — so one broken
-- endpoint disables the rule that would have recovered with it, and every
-- delivery keeps paying for the same discovery.
--
-- The breaker remembers the address itself: after a few terminal failures the
-- address is skipped for a cooldown, and exactly one probe is let through to find
-- out whether it is back. Nothing else is queued behind it, so the rest of the
-- room keeps working.
--
-- A skipped attempt is recorded as `skipped`, not `failed`: `skipped` deliberately
-- changes nothing in the workflow's failure streak and sends no alert, which is
-- the point — the target is already known to be down, and counting it again would
-- pause the rule the breaker exists to protect.
--
-- The state is derived from `next_probe_at`, not only from `state`: a row that is
-- open past its probe time becomes half-open on the next delivery, so there is no
-- background job that has to run for the breaker to recover. Only a real request
-- closes it again — a ping would be a second source of traffic and would prove
-- nothing about accepting a webhook.

CREATE TABLE IF NOT EXISTS forward_targets (
    id                   BIGSERIAL   PRIMARY KEY,
    -- scheme://host[:port] from CircuitBreaker::address(). One row per
    -- destination: two rules forwarding to the same endpoint share its verdict.
    address              TEXT        NOT NULL UNIQUE,
    state                VARCHAR(16) NOT NULL DEFAULT 'closed',
    consecutive_failures INT         NOT NULL DEFAULT 0,
    opened_at            TIMESTAMPTZ,
    next_probe_at        TIMESTAMPTZ,
    last_status          INT,
    last_error           TEXT,
    last_failure_at      TIMESTAMPTZ,
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- The dashboard lists open breakers first. Partial, because almost every row is
-- closed and the index only has to answer "is anything broken".
CREATE INDEX IF NOT EXISTS forward_targets_open_idx
    ON forward_targets (state, next_probe_at)
    WHERE state <> 'closed';

-- Optimistic locking for the action list.
--
-- Two tabs editing one scenario used to end with the last save silently
-- overwriting the other's actions — and a save that swaps a whole action list can
-- drop a forward the other tab had just added. The editor sends the
-- `actions_version` it loaded; a save whose version differs from the stored one
-- is refused with 409 instead of applied. The counter is bumped only when the
-- actions actually change, so renaming a rule does not invalidate another tab's
-- draft.
ALTER TABLE workflows ADD COLUMN IF NOT EXISTS actions_version INT NOT NULL DEFAULT 1;

-- How often this run has been put back in the queue because the breaker refused
-- its target.
--
-- A deferred run is not a failed one, so it does not consume `attempts`. That
-- leaves no natural limit, and a permanently dead address would reschedule the
-- same run every cooldown forever — a pending row nobody can explain in the
-- journal. The counter is the limit: after a few cooldowns the run is finished as
-- `skipped` with the reason, which is the same thing the operator sees on every
-- intermediate pass, just final.
ALTER TABLE workflow_runs ADD COLUMN IF NOT EXISTS deferrals INT NOT NULL DEFAULT 0;
