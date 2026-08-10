<?php

namespace Condoedge\Communications\Reminders\Contracts;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderRun;
use Condoedge\Communications\Reminders\ReminderScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A date-anchored reminder.
 *
 * A reminder declares WHAT it is about and WHICH records are candidates. The runner owns the
 * dangerous parts: the single clock, the exact-day clause, the once-only claim and the dispatch.
 * Nothing below is allowed to re-read now() or to write a date clause — that line is where a '>='
 * instead of a '=' turns into nightly spam for every record in the table, and it is not left to
 * the caller.
 *
 * Extend AbstractDateAnchoredReminder rather than implementing this directly; this exists so the
 * registry has something to type-check at boot.
 */
interface ScheduledReminder
{
    /**
     * Stable identifier, stored on every claim row. Changing it makes everything already sent look
     * unsent, so treat it as permanent.
     */
    public function key(): string;

    /** What this reminder is about, for the command's output. */
    public function description(): string;

    /**
     * The distinct schedules to sweep. One (baseline) today.
     *
     * @return iterable<int, ReminderScope>
     */
    public function scopes(ReminderRun $run): iterable;

    /**
     * Candidate records for this scope and offset, WITH the exact-day clause applied by the base
     * class. Streams with ->cursor().
     *
     * @return iterable<int, Model>
     */
    public function dueAt(ReminderScope $scope, ReminderOffset $offset, ReminderRun $run): iterable;

    /** The date this subject is counted from, or null when it has none right now. */
    public function anchorFor(Model $subject): ?CarbonInterface;

    /** The claim-identity fragment that decides whether a moved anchor may re-arm. */
    public function rearmKeyFor(?CarbonInterface $anchorOn): string;

    /**
     * The event to dispatch, or null to skip — an unreachable recipient, a state that changed
     * since the query ran. Returning null releases the claim, so the record is looked at again
     * rather than silently swallowed.
     *
     * $anchorOn is passed in, read once by the runner. No implementation may re-derive it from
     * now() plus the offset: SISC's BackgroundCheckExpiryReminder does, and the two agree only
     * because the query selected on that arithmetic — they diverge the moment a run straddles
     * midnight.
     *
     * $scope is the schedule the subject was selected under — for logging, and for a host that
     * varies copy per schedule. It is NOT a team hint: adopting $scope->teamId as the event's team
     * overrides the event's own getCommunicationTeams(), and the reminder would silently decide an
     * audience the event declared for itself.
     */
    public function eventFor(
        Model $subject,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        ReminderScope $scope,
    ): ?object;
}
