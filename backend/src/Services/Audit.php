<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Http\Request;
use App\Support\Canonical;
use Throwable;

/**
 * Append-only journal of changes made through the dashboard.
 *
 * Written from the controllers, not from a database trigger: a trigger sees the
 * row but not the actor, the request it came from or which field the operator
 * actually meant to change. The controllers are the only place that knows all
 * three, so they call this explicitly — every mutating handler does.
 *
 * Two rules shape the payload:
 *
 * - **Readable after the fact.** `actor_email`, `room_slug` and `object_label` are
 *   copies, not joins. The log has to explain a deletion after the account was
 *   renamed and the room is gone, which joins cannot do.
 * - **No secrets.** `rooms.secret` and `actions[].secret` are credentials (an HMAC
 *   cannot be checked against a hash, so they are stored as-is). A diff that
 *   included them would turn the audit page into a secret-leaking page, so the
 *   diff is built from an already-redacted view.
 *
 * Writing is best effort, like `Realtime::publish`: the change has already been
 * applied when this runs, so turning a failed audit INSERT into a 500 would report
 * "nothing happened" about something that did.
 */
final class Audit
{
    /** A longer diff is replaced with a size marker instead of a misleading excerpt. */
    private const MAX_CHANGES_BYTES = 4000;

    /**
     * A nested value up to this size is journalled verbatim.
     *
     * The diff has to answer "what changed", and for a condition tree that is the
     * leaf itself: reporting "conditions changed, 1 rule" is technically true and
     * useless. Anything larger (a long action list) is summarized instead, because
     * pasting it into the feed makes the entry unreadable.
     */
    private const MAX_INLINE_BYTES = 600;

    /**
     * @param array{
     *     action: string,
     *     object_type?: string,
     *     object_id?: int|null,
     *     object_label?: string,
     *     room_id?: int|null,
     *     room_slug?: string|null,
     *     summary?: string,
     *     changes?: array<string, mixed>|null
     * } $entry
     */
    public static function record(array $entry, ?Request $request = null): void
    {
        try {
            $user = null;
            if ($request !== null) {
                try {
                    $user = \App\Auth\Auth::user($request);
                } catch (Throwable) {
                    $user = null;
                }
            }

            $changes = $entry['changes'] ?? null;
            if (is_array($changes)) {
                $changes = self::limit($changes);
            }

            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_log
                    (actor_id, actor_email, actor_role, action, object_type, object_id,
                     object_label, room_id, room_slug, summary, changes, ip)
                 VALUES
                    (:actor_id, :actor_email, :actor_role, :action, :object_type, :object_id,
                     :object_label, :room_id, :room_slug, :summary, :changes, :ip)'
            );

            $stmt->execute([
                'actor_id' => $user === null ? null : (int) $user['id'],
                'actor_email' => $user === null ? '' : (string) $user['email'],
                'actor_role' => $user === null ? '' : (string) ($user['role'] ?? ''),
                'action' => (string) $entry['action'],
                'object_type' => (string) ($entry['object_type'] ?? ''),
                'object_id' => isset($entry['object_id']) ? (int) $entry['object_id'] : null,
                'object_label' => mb_substr((string) ($entry['object_label'] ?? ''), 0, 200),
                'room_id' => isset($entry['room_id']) ? (int) $entry['room_id'] : null,
                'room_slug' => $entry['room_slug'] ?? null,
                'summary' => (string) ($entry['summary'] ?? ''),
                'changes' => $changes === null ? null : Canonical::json($changes),
                'ip' => $request?->ip,
            ]);

            // Ids only, like every other event: the audit page re-reads through the
            // API, so a subscriber never has to trust a payload for what changed.
            \App\Services\Realtime::publish('audit.recorded', [
                'id' => (int) Database::pdo()->lastInsertId(),
                'room_id' => isset($entry['room_id']) ? (int) $entry['room_id'] : null,
            ]);
        } catch (Throwable $e) {
            error_log('[audit] record failed: ' . $e->getMessage());
        }
    }

    /**
     * Field-by-field diff of two already-normalized structures.
     *
     * Only the keys that actually differ are kept: a full "before" and "after" of a
     * workflow would repeat the same condition tree on every rename and make the
     * page unreadable. Nested arrays (an action list) are compared as opaque values
     * and reported with a `count` instead of their content — the interesting fact
     * is "the actions changed, 4 → 5", not the body of each forward.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $fields
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if (Canonical::json($old) === Canonical::json($new)) {
                continue;
            }

            if (is_array($old) || is_array($new)) {
                $changes[$field] = [self::shape($old), self::shape($new)];
                continue;
            }

            $changes[$field] = [self::scalar($old), self::scalar($new)];
        }

        return $changes;
    }

    /**
     * Drops the credential fields a caller must never journal.
     *
     * Applied to both sides of a diff so a forwarded action is described by its URL
     * and signature state rather than by the secret that signs it.
     *
     * @param list<array<string, mixed>> $actions
     * @return list<array<string, mixed>>
     */
    public static function redactActions(array $actions): array
    {
        $clean = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            if ((string) ($action['type'] ?? '') === 'forward') {
                $action['signed'] = (string) ($action['secret'] ?? '') !== '';
                unset($action['secret']);
            }

            $clean[] = $action;
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>|string a value small enough for the journal
     */
    private static function scalar(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && mb_strlen($value) > 300) {
            return mb_substr($value, 0, 300) . '…';
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|int|string
     */
    private static function shape(mixed $value): array|int|string
    {
        if (!is_array($value)) {
            return self::scalar($value);
        }

        // A small structure is kept verbatim: "the condition value went from x to
        // y" is the whole point of the entry, and reducing it to a leaf count would
        // report a change while hiding what changed. Only a structure too large to
        // read in one line is summarized.
        if (strlen(Canonical::json($value)) <= self::MAX_INLINE_BYTES) {
            return $value;
        }

        if (array_is_list($value)) {
            return ['count' => count($value)];
        }

        return ['_truncated' => count($value) . ' полей'];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function limit(array $changes): array
    {
        $encoded = Canonical::json($changes);
        if (strlen($encoded) <= self::MAX_CHANGES_BYTES) {
            return $changes;
        }

        return ['_truncated' => strlen($encoded) . ' bytes'];
    }
}
