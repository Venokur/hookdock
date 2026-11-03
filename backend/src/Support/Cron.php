<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Five-field cron expressions, evaluated in UTC.
 *
 * Deliberately small: `*`, `n`, `a-b`, `*\/n`, `a-b/n` and comma lists. No month or
 * weekday names, no `@daily`, no `?`, `L`, `W` or `#`. Those shorthands are where
 * a "just use a library" parser starts accepting expressions whose meaning differs
 * between implementations; a rule that fires on a schedule nobody can predict is
 * worse than a rule that refuses to save.
 *
 * Day-of-week accepts `7` as Sunday (Vixie cron) and normalises it to `0`, so `5-7`
 * means Friday, Saturday, Sunday rather than failing the range check.
 *
 * Time is UTC on purpose: the scheduler and the database agree on one clock, and a
 * rule that fires at "09:00" does not silently move when the host timezone changes.
 */
final class Cron
{
    /** Inclusive bounds of each field, in cron order. */
    private const BOUNDS = [
        [0, 59], // minute
        [0, 23], // hour
        [1, 31], // day of month
        [1, 12], // month
        [0, 7],  // day of week (0 and 7 are Sunday)
    ];

    /** How far ahead `nextAfter` is willing to look before giving up. */
    private const HORIZON_DAYS = 366 * 5;

    /**
     * @return array{minutes: list<int>, hours: list<int>, days: list<int>, months: list<int>, weekdays: list<int>}|null
     *         null when the expression is malformed
     */
    public static function parse(string $expression): ?array
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return null;
        }

        $sets = [];
        foreach ($parts as $index => $part) {
            $set = self::expand($part, self::BOUNDS[$index][0], self::BOUNDS[$index][1]);
            if ($set === null) {
                return null;
            }
            $sets[] = $set;
        }

        // 7 and 0 are the same day; done after expanding so `5-7` still passes the
        // range check above.
        $sets[4] = array_values(array_unique(array_map(
            static fn (int $day): int => $day === 7 ? 0 : $day,
            $sets[4],
        )));
        sort($sets[4]);

        return [
            'minutes' => $sets[0],
            'hours' => $sets[1],
            'days' => $sets[2],
            'months' => $sets[3],
            'weekdays' => $sets[4],
        ];
    }

    public static function isValid(string $expression): bool
    {
        return self::parse($expression) !== null;
    }

    /**
     * The first moment strictly after `$from` that the expression matches.
     *
     * Returns null when the expression is invalid **or** never fires — `0 0 31 2 *`
     * is well-formed and matches no date, and a rule saved with it would sit in the
     * queue forever. The caller turns that into a `422` instead.
     */
    public static function nextAfter(string $expression, \DateTimeImmutable $from): ?\DateTimeImmutable
    {
        $sets = self::parse($expression);
        if ($sets === null) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        $domRestricted = !str_starts_with($parts[2], '*');
        $dowRestricted = !str_starts_with($parts[4], '*');

        // Start at the next whole minute: cron has minute resolution, so a slot at
        // 12:00:30 has already passed.
        $cursor = $from->setTimezone($utc)->setTime(
            (int) $from->setTimezone($utc)->format('G'),
            (int) $from->setTimezone($utc)->format('i'),
            0,
        )->modify('+1 minute');

        for ($day = 0; $day < self::HORIZON_DAYS; $day++) {
            if (!in_array((int) $cursor->format('n'), $sets['months'], true)) {
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0);
                continue;
            }

            if (!self::dayMatches($cursor, $sets, $domRestricted, $dowRestricted)) {
                $cursor = $cursor->modify('+1 day')->setTime(0, 0);
                continue;
            }

            $hour = (int) $cursor->format('G');
            $minute = (int) $cursor->format('i');

            if (in_array($hour, $sets['hours'], true)) {
                foreach ($sets['minutes'] as $candidate) {
                    if ($candidate >= $minute) {
                        return $cursor->setTime($hour, $candidate);
                    }
                }
            }

            foreach ($sets['hours'] as $candidate) {
                if ($candidate > $hour) {
                    return $cursor->setTime($candidate, $sets['minutes'][0]);
                }
            }

            // No slot left today.
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        }

        return null;
    }

    /**
     * A one-line Russian description for the editor and the rule card.
     *
     * Only the shapes people actually type get a sentence; everything else falls
     * back to naming the expression, because a wrong "every 15 minutes" for
     * `0/15 9-17 * * 1-5` is worse than no sentence at all.
     */
    public static function describe(string $expression): string
    {
        $sets = self::parse($expression);
        if ($sets === null) {
            return '';
        }

        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        [$minute, $hour] = [$parts[0], $parts[1]];
        $everyDay = count($sets['days']) === 31
            && count($sets['months']) === 12
            && count($sets['weekdays']) === 7;

        if ($everyDay && $minute === '*' && $hour === '*') {
            return 'каждую минуту';
        }

        if ($everyDay && preg_match('#^\*/(\d+)$#', $minute, $match) === 1 && $hour === '*') {
            return 'каждые ' . $match[1] . ' мин.';
        }

        if ($everyDay && preg_match('#^\*/(\d+)$#', $hour, $match) === 1 && $minute === '0') {
            return 'каждые ' . $match[1] . ' ч.';
        }

        if ($everyDay && $hour === '*' && ctype_digit($minute)) {
            return 'каждый час в :' . str_pad($minute, 2, '0', STR_PAD_LEFT);
        }

        if ($everyDay && ctype_digit($minute) && ctype_digit($hour)) {
            return 'ежедневно в ' . str_pad($hour, 2, '0', STR_PAD_LEFT)
                . ':' . str_pad($minute, 2, '0', STR_PAD_LEFT) . ' UTC';
        }

        return 'по выражению ' . trim($expression) . ' (UTC)';
    }

    /**
     * @param array{minutes: list<int>, hours: list<int>, days: list<int>, months: list<int>, weekdays: list<int>} $sets
     */
    private static function dayMatches(
        \DateTimeImmutable $cursor,
        array $sets,
        bool $domRestricted,
        bool $dowRestricted,
    ): bool {
        $domOk = in_array((int) $cursor->format('j'), $sets['days'], true);
        $dowOk = in_array((int) $cursor->format('w'), $sets['weekdays'], true);

        // Standard cron: when both day fields are restricted, either one matching
        // is enough. Treating them as AND makes "the 1st and every Monday" impossible
        // to express, which is the shape people actually write.
        if ($domRestricted && $dowRestricted) {
            return $domOk || $dowOk;
        }

        if ($domRestricted) {
            return $domOk;
        }

        if ($dowRestricted) {
            return $dowOk;
        }

        return true;
    }

    /**
     * @return list<int>|null sorted unique values, null when the field is invalid
     */
    private static function expand(string $field, int $min, int $max): ?array
    {
        $values = [];

        foreach (explode(',', $field) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                return null;
            }

            $step = 1;
            $range = $chunk;

            if (str_contains($chunk, '/')) {
                [$range, $stepRaw] = explode('/', $chunk, 2);
                if (!ctype_digit($stepRaw)) {
                    return null;
                }
                $step = (int) $stepRaw;
                if ($step < 1) {
                    return null;
                }
            }

            if ($range === '*') {
                $from = $min;
                $to = $max;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $range, $match) === 1) {
                $from = (int) $match[1];
                $to = (int) $match[2];
                if ($from > $to) {
                    return null;
                }
            } elseif (ctype_digit($range)) {
                $from = (int) $range;
                // `5/10` means "from 5, every 10" — the step needs a range to run to.
                $to = $step === 1 ? $from : $max;
            } else {
                return null;
            }

            if ($from < $min || $to > $max) {
                return null;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        if ($values === []) {
            return null;
        }

        $out = array_keys($values);
        sort($out);

        return $out;
    }
}
