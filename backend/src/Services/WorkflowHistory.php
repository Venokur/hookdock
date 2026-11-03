<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Http\Request;
use App\Support\Canonical;
use Throwable;

/**
 * Snapshot history of a scenario.
 *
 * `actions_version` protects two editors from each other; it says nothing to the
 * operator who saved a wrong rule yesterday. Every save therefore writes the whole
 * configuration as a row here, which makes "put back the version that worked" a
 * read and an update instead of a copy-paste from memory.
 *
 * The snapshot is stored **unredacted**, exactly as `workflows.actions` stores it,
 * because a restored signed forward has to keep its secret or it would silently
 * start sending unsigned requests. Reads go through the same `redactActions()` as
 * reads of the rule itself, so the value still never leaves the API.
 *
 * Snapshots are written after the change has been applied, and a failure is only
 * logged: losing a history row must not turn a successful edit into a 500.
 */
final class WorkflowHistory
{
    /**
     * Records the current stored state as the next version.
     *
     * @param array<string, mixed> $row the `workflows` row after the change, as
     *        returned by `RETURNING *` (jsonb columns may still be strings)
     */
    public static function snapshot(array $row, string $change, ?Request $request = null, ?string $actorEmail = null): void
    {
        try {
            $workflowId = (int) ($row['id'] ?? 0);
            if ($workflowId <= 0) {
                return;
            }

            $pdo = Database::pdo();

            // One statement, so two saves in flight cannot both claim `version = n`:
            // the unique `(workflow_id, version)` index turns the loser into a
            // caught error instead of a duplicated version number.
            $stmt = $pdo->prepare(
                'INSERT INTO workflow_versions
                    (workflow_id, room_id, version, actions_version, name, endpoint, enabled,
                     conditions, actions, priority, stop_processing, schedule_cron, actor_id, actor_email, change)
                 SELECT :workflow_id, w.room_id,
                        COALESCE((SELECT max(v.version) FROM workflow_versions v WHERE v.workflow_id = :workflow_id), 0) + 1,
                        w.actions_version, w.name, w.endpoint, w.enabled,
                        w.conditions, w.actions, w.priority, w.stop_processing, w.schedule_cron,
                        :actor_id, :actor_email, :change
                   FROM workflows w
                  WHERE w.id = :workflow_id'
            );

            $actor = null;
            if ($request !== null) {
                try {
                    $actor = \App\Auth\Auth::user($request);
                } catch (Throwable) {
                    $actor = null;
                }
            }

            $stmt->execute([
                'workflow_id' => $workflowId,
                'actor_id' => $actor === null ? null : (int) $actor['id'],
                'actor_email' => $actor === null
                    ? mb_substr((string) $actorEmail, 0, 255)
                    : (string) $actor['email'],
                'change' => $change,
            ]);
        } catch (Throwable $e) {
            error_log('[history] snapshot failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>|null the stored snapshot, `actions` still raw
     */
    public static function find(int $workflowId, int $version): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM workflow_versions WHERE workflow_id = :workflow_id AND version = :version'
        );
        $stmt->execute(['workflow_id' => $workflowId, 'version' => $version]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return \App\Support\JsonColumns::decode($row, ['conditions', 'actions']);
    }

    /**
     * Whether a snapshot carries the same configuration as the stored row, which
     * decides if a rollback also has to bump `actions_version`.
     *
     * Without this a rollback that only renames a rule would invalidate the other
     * tab's draft for no reason, exactly like a plain save that changes nothing.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $current
     */
    public static function sameActions(array $snapshot, array $current): bool
    {
        return Canonical::json($snapshot['actions'] ?? [])
            === Canonical::json($current['actions'] ?? []);
    }
}
