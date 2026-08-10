<?php

namespace Condoedge\Communications\Reminders\Console;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\Contracts\ScheduledReminder;
use Condoedge\Communications\Reminders\ReminderClaim;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderRegistry;
use Condoedge\Communications\Reminders\ReminderRun;
use Condoedge\Communications\Reminders\ReminderScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Walks every registered reminder and raises the offsets that fall today.
 *
 * One command for all of them rather than one per deadline: the awkward parts - firing once and
 * only once, surviving a re-run, being inspectable before it writes to anyone - are the same every
 * time, and are the reason SISC had no deadline emails at all.
 */
class SendScheduledRemindersCommand extends Command
{
    protected $signature = 'communications:send-reminders
                            {--dry-run : List what would be sent and write nothing}
                            {--only= : Run a single reminder, by key}
                            {--hour= : Override the hour to sweep for (0-23)}
                            {--date= : Override today (Y-m-d). For backfills and tests.}';

    protected $description = 'Fire date-anchored reminders whose anchor lands on today +/- a configured offset.';

    public function handle(ReminderRegistry $registry, ReminderLedger $ledger): int
    {
        $reminders = $registry->all($this->option('only'));

        // Returned before buildRun(), so a malformed --hour or --date is NOT refused when nothing
        // is registered. Deliberate: with an empty registry the guard has nothing to protect — no
        // scope of any reminder was going to be swept at any hour — and moving the refusal above
        // this line would only change which of two messages an operator reads on a run that was
        // always going to do nothing. As soon as one reminder matches, buildRun() runs and refuses.
        if ($reminders->isEmpty()) {
            $this->warn('No reminder matched.');

            return self::SUCCESS;
        }

        $run = $this->buildRun();

        $completed = 0;
        $brokenOffsets = 0;
        $unschedulable = 0;

        foreach ($reminders as $reminder) {
            try {
                [$ok, $failed] = $this->runReminder($reminder, $ledger, $run);

                $completed += $ok;
                $brokenOffsets += $failed;
            } catch (\Throwable $e) {
                // Counted apart from $brokenOffsets, not added to it: this is a reminder that could
                // not enumerate its own schedule, which is a different event from an offset that
                // failed while running, and the exit code below has to weigh the two differently.
                $unschedulable++;

                Log::error('Scheduled reminder failed entirely', [
                    'reminder' => $reminder->key(),
                    'exception' => $e,
                ]);

                $this->error("  {$reminder->key()}: failed - see the log");
            }
        }

        // Only a total outage is a failure. One chronically broken reminder returning non-zero
        // every hour trains everyone to ignore the alert.
        //
        // Two shapes of outage, tested separately because they leave different traces.
        //
        // ATTEMPTED OFFSETS, not reminders, for the first: runReminder() catches per offset and
        // returns normally afterwards, so counting a reminder as completed merely because control
        // came back means a run in which every offset of every reminder threw - a dropped table, a
        // DB outage, a host subjectQuery() naming a column that no longer exists, all of which fail
        // inside dueAt() - still exits 0. That is the wholly dead sweeper this exit code exists to
        // make visible, and it is the likelier of the two.
        //
        // Offsets alone cannot carry the second shape, and gating ONLY on them is a bug this code
        // has already had: at any hour where no scope matches, every healthy reminder short-circuits
        // to zero offsets, so a reminder throwing out of scopes() was the only thing counted and the
        // sweep exited 1 on 23 of every 24 hourly runs - the exact alert fatigue the first line of
        // this comment forbids. A scopes() failure therefore counts only when EVERY registered
        // reminder suffered one: nothing in the install can so much as enumerate its own schedule,
        // which is an outage at any hour, and which cannot be the off-hour case because there the
        // healthy reminders return without throwing.
        $attempted = $completed + $brokenOffsets;

        $deadSweep = $attempted > 0 && $completed === 0;
        $nothingCanSchedule = $attempted === 0 && $unschedulable === $reminders->count();

        return $deadSweep || $nothingCanSchedule ? self::FAILURE : self::SUCCESS;
    }

    protected function buildRun(): ReminderRun
    {
        $hour = $this->option('hour') === null ? null : $this->hourFrom($this->option('hour'), '--hour');

        $date = $this->option('date');

        // `!== null`, not truthiness: '' and '0' are both falsy and both PRESENT. A truthiness test
        // folds them into "no --date was given" and sweeps TODAY instead, so a backfill operator's
        // typo runs against the wrong calendar day without saying so - and outside a dry run it
        // writes claims against that day which no later run will re-arm. Under `!== null` the same
        // input reaches CarbonImmutable::parse(), which refuses '0' by name; the refusal is the
        // point. Same rule, same reason, as ReminderScope::label() and ReminderRegistry::all().
        //
        // '' is the case Carbon does NOT refuse: parse('', $tz) answers TODAY rather than throwing,
        // so `!== null` alone lets the one input that reproduces the truthiness bug straight
        // through - and worse than the truthiness reading would, because the backfill branch also
        // takes default_hour instead of the wall-clock hour. `--date=` and `--date=$BACKFILL_DATE`
        // with the variable unset both arrive as string(0) "" (a bare `--date` with no `=` arrives
        // as null, which IS "no date"), so this is likelier than the '0' typo Carbon does catch.
        // Refused here by name, so the branch means "a date was supplied", not "a string was".
        if ($date !== null) {
            if (trim($date) === '') {
                throw new \InvalidArgumentException('--date must be a Y-m-d date, got an empty string.');
            }

            $tz = config('kompo-communications.reminders.timezone') ?? config('app.timezone');

            // parse() throws InvalidFormatException on 'garbage' and on '2026-13-45', which is what
            // F3 asks of an edit-time input, and it aborts the whole command because buildRun() is
            // called outside the per-reminder try/catch below. It does NOT throw on a calendar
            // overflow: '2026-02-30' rolls forward to 2026-03-02. Left unguarded and written down
            // instead of guarded, because that case still sweeps a real day and still PRINTS a
            // per-offset count line, so an operator sees a sweep they can compare against the date
            // they typed. An out-of-range --hour is guarded precisely because it prints nothing at
            // all. Add a checkdate() guard here the first time a rolled-over backfill actually
            // misleads someone.
            return new ReminderRun(
                CarbonImmutable::parse($date, $tz)->startOfDay(),
                // `?? 9`, never config(..., 9) - see hourFrom()'s note on why the second argument
                // does not defend this key. Read through the same rejection as --hour so that a
                // backfill and a scheduled sweep cannot disagree about what the default hour is.
                $hour ?? $this->hourFrom(
                    config('kompo-communications.reminders.default_hour') ?? 9,
                    'kompo-communications.reminders.default_hour',
                ),
                (bool) $this->option('dry-run'),
            );
        }

        return ReminderRun::now((bool) $this->option('dry-run'), null, $hour);
    }

    /**
     * An hour from an untrusted scalar - a CLI option, a published config value - or a refusal
     * naming where it came from.
     *
     * REJECTED rather than cast, for the reason AbstractDateAnchoredReminder::sendAt() spells out
     * about the same config key: (int) is what re-opens the hole ReminderScope's HH:MM guard was
     * written to close. (int) 'noon', (int) null and (int) '' are all 0, and 0 is a PERFECTLY VALID
     * hour - so no range check anywhere downstream can ever see them, and the whole sweep silently
     * relocates to midnight with no exception, no log line and a green exit code. config()'s second
     * argument does not save this either: it fires only on a MISSING key, and this package now
     * ships 'default_hour' => 9, so the key is always present. A host publishing the file as
     * `'default_hour' => env('COMMUNICATIONS_REMINDER_HOUR')` with the env unset arrives here as
     * null, not as 9 - hence the `?? 9` at the call site.
     *
     * Range (0-23) is deliberately NOT checked here: ReminderRun's constructor owns it, so a
     * hand-built backfill run is covered by the same guard as this command's --hour.
     *
     * Written out here rather than shared with sendAt(). The two answer different questions from
     * different inputs - sendAt() turns a config value into the 'HH:MM' string a ReminderScope is
     * built from, this turns a config value OR a CLI string into the int a ReminderRun is built
     * from - so a helper general over both would be an abstraction over a similarity of FORM, not
     * over any variation actually observed. Three copies of this parse now exist (sendAt, hourFrom,
     * daysFrom). Extract the parse half - never the range half, which each caller owns - the first
     * time one of them is HARDENED, because that fix must land in all three or it leaves the same
     * hole open twice.
     */
    protected function hourFrom(mixed $value, string $source): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            return (int) $value;
        }

        $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        throw new \InvalidArgumentException("{$source} must be an integer hour, got {$shown}.");
    }

    /**
     * @return array{0: int, 1: int} offsets that completed, offsets that threw
     */
    protected function runReminder(ScheduledReminder $reminder, ReminderLedger $ledger, ReminderRun $run): array
    {
        $header = false;
        $completed = 0;
        $broken = 0;

        foreach ($reminder->scopes($run) as $scope) {
            // The 23-in-24 short circuit. Costs nothing but the scope construction - not a single
            // subject query.
            if ($scope->hour() !== $run->hour) {
                continue;
            }

            // Printed INSIDE the loop and after the short circuit, never before it: a header for a
            // reminder whose every scope was skipped tells an operator comparing a dry run against
            // the old code that something was considered when nothing was. The flag is what keeps
            // it to one line once a reminder returns more than the single baseline scope it does
            // today.
            if (!$header) {
                $this->newLine();
                $this->line("<comment>{$reminder->key()}</comment> - {$reminder->description()}");
                $header = true;
            }

            foreach ($scope->offsets as $offset) {
                try {
                    $this->runOffset($reminder, $ledger, $run, $scope, $offset);
                    $completed++;
                } catch (\Throwable $e) {
                    $broken++;

                    Log::error('Scheduled reminder offset failed', [
                        'reminder' => $reminder->key(),
                        'scope' => $scope->label(),
                        'offset' => $offset->storageKey(),
                        'exception' => $e,
                    ]);

                    $this->error("  {$offset->storageKey()} ({$scope->label()}): failed - see the log");
                }
            }
        }

        return [$completed, $broken];
    }

    protected function runOffset(
        ScheduledReminder $reminder,
        ReminderLedger $ledger,
        ReminderRun $run,
        ReminderScope $scope,
        ReminderOffset $offset,
    ): void {
        $sent = 0;
        $skipped = 0;
        $held = 0;
        $released = 0;
        $noAnchor = 0;

        foreach ($reminder->dueAt($scope, $offset, $run) as $subject) {
            $anchorOn = $reminder->anchorFor($subject);

            // No claim is taken for a null anchor: claiming and releasing would open a window in
            // which a concurrent run stands down and nobody sends at all.
            //
            // `=== null`, not `!$anchorOn`: safe today only because the declared return type is
            // ?CarbonInterface and no Carbon instance is falsy - i.e. by accident of a signature a
            // host implements, not by the rule buildRun() argues for --date above and ReminderScope,
            // ReminderRegistry and ReminderLedger each argue in place. Written the way the rule says
            // so the next reader does not have to re-derive the accident to know the line is safe.
            if ($anchorOn === null) {
                $noAnchor++;
                continue;
            }

            $rearmKey = $reminder->rearmKeyFor($anchorOn);

            if ($run->dryRun) {
                // eventFor() is NOT called here, so this counts CANDIDATES, not sends: a subject
                // whose eventFor() returns null - an unreachable recipient, a state that changed
                // since the query ran, both first-class returns of that contract - is counted as
                // sendable and OVERSTATES the sweep by exactly the number a host filters out. The
                // truer number was rejected: eventFor() is host code, and running host code inside
                // a run advertised as writing nothing is how --dry-run acquires the side effect
                // nobody expected. Over-reporting is the safe direction. Under-reporting would tell
                // an operator that fifty thousand people are not about to be emailed.
                //
                // 'already claimed', not 'already handled': alreadyHandled() answers whether a row
                // EXISTS, which is a run still in flight as much as a completed one - the two
                // situations ReminderClaim was made an enum to keep apart.
                $ledger->alreadyHandled($reminder->key(), $offset, $rearmKey, $subject) ? $skipped++ : $sent++;
                continue;
            }

            // Claimed before dispatch: the unique index is what makes this safe against a second
            // run, not any check the code could make first.
            $claim = $ledger->claim(
                $reminder->key(),
                $offset,
                $rearmKey,
                $subject,
                $anchorOn,
                null,
                $scope->teamId,
            );

            // Three cases, not `!== CLAIMED`, and each counted apart. Collapsing them prints an
            // in-flight run's row under the words 'already handled', which is the exact reading
            // ReminderClaim was made an enum to prevent: a bad interleaving - two crons overlapping,
            // a sweep that outlives its hour - then looks identical to the ordinary steady state and
            // nothing anywhere reports it. HELD_BY_ANOTHER_RUN is also how an ORPHANED claim reads:
            // a run killed mid-sweep leaves an undispatched row that nothing releases and
            // prune_after_days keeps for 400 days, during which those subjects are never sent to and
            // the only console line saying so is this one.
            if ($claim === ReminderClaim::HELD_BY_ANOTHER_RUN) {
                $held++;
                continue;
            }

            if ($claim === ReminderClaim::ALREADY_HANDLED) {
                $skipped++;
                continue;
            }

            try {
                // `=== null`, not `!$event` - same rule as the anchor guard above.
                $event = $reminder->eventFor($subject, $offset, $anchorOn, $scope);

                if ($event === null) {
                    // Nothing to send after all - release so the record is looked at again rather
                    // than silently swallowed.
                    //
                    // Counted as RELEASED, never as skipped: nothing was handled, the row was
                    // deleted, and the record is deliberately re-armed for the next sweep. Reported
                    // under 'already handled' it reads as covered, which is the one thing it is not.
                    $ledger->release($reminder->key(), $offset, $rearmKey, $subject);
                    $released++;
                    continue;
                }

                event($event);

                $ledger->markDispatched(
                    $reminder->key(),
                    $offset,
                    $rearmKey,
                    $subject,
                    method_exists($event, 'getIdempotencyKey') ? $event->getIdempotencyKey() : null,
                );

                $sent++;
            } catch (\Throwable $e) {
                // The claim STANDS. A reminder that throws must not come back every hour; it is
                // logged instead, where it can be seen.
                Log::error('Scheduled reminder failed to dispatch', [
                    'reminder' => $reminder->key(),
                    'offset' => $offset->storageKey(),
                    'subject' => $subject->getMorphClass() . '#' . $subject->getKey(),
                    'exception' => $e,
                ]);

                $this->error("  {$offset->storageKey()}: {$subject->getMorphClass()}#{$subject->getKey()} failed - see the log");
            }
        }

        // 'already claimed' on the dry path, 'already handled' on the real one, because the two
        // numbers are read from different things: the dry branch reads alreadyHandled(), whose own
        // docblock states it answers whether a ROW EXISTS - dispatched or still in flight - while
        // the real branch has the enum and has already split the in-flight case out into $held.
        $this->line(sprintf(
            '  %-5s %-12s %4d to send%s%s%s%s',
            $offset->storageKey(),
            $scope->label(),
            $sent,
            $skipped ? ($run->dryRun ? ", {$skipped} already claimed" : ", {$skipped} already handled") : '',
            $held ? ", {$held} held by another run" : '',
            $released ? ", {$released} released" : '',
            $noAnchor ? ", {$noAnchor} without an anchor" : '',
        ));
    }
}
