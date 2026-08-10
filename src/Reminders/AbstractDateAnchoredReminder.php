<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\Contracts\HasReminderAnchor;
use Condoedge\Communications\Reminders\Contracts\ScheduledReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a date-anchored reminder needs that is identical across every one of them.
 *
 * A concrete reminder declares four things: its identity, its offsets, its candidate query
 * (filters only, never a date), and how to build the event. The exact-day clause, the clock, the
 * anchor read and the rearm key are here, once, because each of them is somewhere SISC's three
 * copies had already begun to diverge.
 *
 * SEAM FOR §6: scopes() returns a single baseline scope built from offsets(). When §6's
 * TriggerParameters lands, per-team schedules are an override of scopes() and nothing else — the
 * storage format, the sweeper and the ledger are unaffected.
 */
abstract class AbstractDateAnchoredReminder implements ScheduledReminder
{
    abstract public function key(): string;

    abstract public function description(): string;

    /**
     * The moments on this reminder's timeline.
     *
     * @return ReminderOffset[]
     */
    abstract protected function offsets(): array;

    /**
     * The date column or expression the exact-day clause is applied to. A string is a column;
     * anything else is passed to whereDate() untouched, so a join, a COALESCE or a computed
     * interval all work without the package knowing what they compute.
     *
     * It MUST agree with what the subject's reminderAnchorDate() returns. They are two readings of
     * one date — one the database can filter on, one the template can print — and a disagreement
     * is how a recipient is told a deadline that is not the deadline they were selected for.
     *
     * @return string|\Illuminate\Contracts\Database\Query\Expression
     */
    abstract protected function anchorExpression();

    /** Domain filters only. No date clause — the base class owns that line. */
    abstract protected function subjectQuery(ReminderScope $scope): Builder;

    abstract public function eventFor(
        Model $subject,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        ReminderScope $scope,
    ): ?object;

    // ---- selection -------------------------------------------------------

    /**
     * The one place in the whole layer that writes a date clause.
     *
     * whereDate(..., '=') and never a range. A range re-selects the same record every night until
     * the ledger catches it, which only works while the ledger is intact — and it makes the ledger
     * load-bearing for correctness rather than for once-only, which are different jobs.
     */
    public function dueAt(ReminderScope $scope, ReminderOffset $offset, ReminderRun $run): iterable
    {
        return $this->subjectQuery($scope)
            ->whereDate($this->anchorExpression(), '=', $offset->targetDateFrom($run->today)->toDateString())
            ->cursor();
    }

    /**
     * A null here is a DATA state — no date on this record right now — and the runner skips one
     * row. A subject class that does not implement HasReminderAnchor AT ALL is a different animal:
     * every row answers null, the sweep sends nothing and exits green.
     *
     * Nothing in this layer can tell the two apart from one call, and the wiring case is CURRENTLY
     * UNCAUGHT — nothing at boot inspects a reminder's subject model. That is a known gap, not an
     * oversight, and it is left open because both shapes a boot check would have to tolerate are
     * out of its reach. The subject class is reachable only through the abstract protected
     * subjectQuery(), which needs a ReminderScope and a database round trip to answer at all; and a
     * reminder may legitimately own its anchor by overriding anchorFor() to read a column its
     * subject model never exposes through the contract, so a check that simply demanded
     * HasReminderAnchor on the subject would reject a correctly wired reminder at boot — trading a
     * silent sweep for an install that will not boot.
     */
    public function anchorFor(Model $subject): ?CarbonInterface
    {
        return $subject instanceof HasReminderAnchor
            ? $subject->reminderAnchorDate($this->key())
            : null;
    }

    // ---- rearming --------------------------------------------------------

    protected function rearmPolicy(): RearmPolicy
    {
        return RearmPolicy::ONCE_EVER;
    }

    public function rearmKeyFor(?CarbonInterface $anchorOn): string
    {
        return match ($this->rearmPolicy()) {
            RearmPolicy::ONCE_EVER       => 'once',
            RearmPolicy::PER_ANCHOR_DATE => $anchorOn?->toDateString() ?? 'none',
        };
    }

    // ---- scopes ----------------------------------------------------------

    public function scopes(ReminderRun $run): iterable
    {
        return [new ReminderScope(null, $this->dedupe($this->offsets()), $this->sendAt())];
    }

    /**
     * The time of day this reminder sweeps at, 'HH:MM'.
     *
     * The config value is REJECTED rather than cast, because (int) is what re-opens the hole
     * ReminderScope's HH:MM guard was written to close: 'noon', an unset env() and a key present
     * but null all cast to 0, produce a well-formed '00:00', pass that guard, and move the whole
     * sweep to midnight with no exception, no log line and a green exit code. config()'s second
     * argument does not save this — it applies only to a MISSING key, so a shipped
     * `'default_hour' => env('COMMUNICATIONS_REMINDER_HOUR')` with the env unset returns null, not
     * 9. Out-of-range numbers already die at ReminderScope; non-numbers must die here or they
     * never die at all.
     */
    protected function sendAt(): string
    {
        $hour = config('kompo-communications.reminders.default_hour') ?? 9;

        if (!is_int($hour) && !(is_string($hour) && preg_match('/^\d+$/', $hour))) {
            $shown = is_scalar($hour) ? var_export($hour, true) : get_debug_type($hour);

            throw new \InvalidArgumentException(
                "kompo-communications.reminders.default_hour must be an integer hour, got {$shown}."
            );
        }

        return sprintf('%02d:00', (int) $hour);
    }

    /**
     * @param  ReminderOffset[]  $offsets
     * @return ReminderOffset[]
     */
    protected function dedupe(array $offsets): array
    {
        $seen = [];

        foreach ($offsets as $offset) {
            $seen[$offset->storageKey()] ??= $offset;
        }

        // Furthest-out first, so the console output reads as a timeline.
        uasort($seen, fn ($a, $b) => $b->signedDays() <=> $a->signedDays());

        return array_values($seen);
    }
}
