-- Failure alerting and automatic pausing of a workflow.
--
-- A broken forward target used to fail silently: the receiver already answered
-- 202, so nobody opens the run log until a sender complains. Three columns make
-- the failure visible in the UI and stop the noise:
--
--   consecutive_failures – terminal failures in a row; a success resets it;
--   disabled_reason      – set only by the automatic pause, so the banner can say
--                          why the rule stopped (a manual toggle clears it);
--   disabled_at          – when that happened;
--   last_alert_at        – throttles the notification, so a target that is down for
--                          an hour does not post a message per webhook.
--
-- The threshold lives in the worker (`WORKFLOW_FAILURE_THRESHOLD`, 0 = never
-- pause automatically) so it can be tuned without a migration.

ALTER TABLE workflows ADD COLUMN IF NOT EXISTS consecutive_failures INTEGER NOT NULL DEFAULT 0;
ALTER TABLE workflows ADD COLUMN IF NOT EXISTS disabled_reason TEXT;
ALTER TABLE workflows ADD COLUMN IF NOT EXISTS disabled_at TIMESTAMPTZ;
ALTER TABLE workflows ADD COLUMN IF NOT EXISTS last_alert_at TIMESTAMPTZ;
