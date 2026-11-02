-- Replaying a stored delivery *with edits*.
--
-- `POST /api/requests/{id}/replay` re-runs the room's rules against the row that
-- is already stored, which is right for "the rule was wrong, try it again" but
-- useless for "the payload was wrong": the operator cannot fix a field and see
-- whether the rule would have matched.
--
-- An edited replay therefore stores a **new** row instead of mutating the
-- original. The log is the product's evidence: rewriting the stored payload would
-- erase what the sender actually sent, and the run's step log would then describe
-- a request that never arrived.
--
-- `duplicate_of` is deliberately not reused. That column means "the sender
-- retried a delivery we already routed", and the worker plus the log treat such a
-- row as *not* routed; a redelivery is routed by definition. `replayed_from` says
-- "a human produced this from #N", which is a different fact and has to survive a
-- delete of the original.

ALTER TABLE incoming_requests
    ADD COLUMN IF NOT EXISTS replayed_from BIGINT REFERENCES incoming_requests (id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS incoming_requests_replayed_from_idx
    ON incoming_requests (replayed_from)
    WHERE replayed_from IS NOT NULL;
