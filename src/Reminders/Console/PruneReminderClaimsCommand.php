<?php

namespace Condoedge\Communications\Reminders\Console;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Reminders\ReminderRegistry;
use Illuminate\Console\Command;

/**
 * Deletes claim rows old enough that nobody will ask about them again.
 *
 * Scoped to REGISTERED reminders on purpose. A blanket "delete everything older than N" would also
 * delete the claims of a reminder someone temporarily unregistered - and re-registering it would
 * then re-send its entire backlog to everyone still sitting at a milestone.
 */
class PruneReminderClaimsCommand extends Command
{
    protected $signature = 'communications:prune-reminder-claims
                            {--dry-run : Report what would be deleted and delete nothing}
                            {--days= : Override the retention window, in days}';

    protected $description = 'Delete reminder claim rows older than the configured retention window.';

    public function handle(ReminderRegistry $registry, ReminderLedger $ledger): int
    {
        // Resolved and refused BEFORE the registry is read, unlike SendScheduledRemindersCommand's
        // --hour, which defers past an empty registry: a bad window here is not a run that does
        // nothing, it is a run that empties the table the moment a reminder is registered, and the
        // hosts with nothing registered yet are exactly the ones where nobody would notice. Do not
        // move this below an emptiness guard.
        //
        // `=== null`, not `??` over the option itself: `--days=` and `--days=$RETENTION` with the
        // variable unset both arrive as string(0) "", which is PRESENT. Read with `??` (or with a
        // truthiness test) an empty string is not null, so it flows straight into the window as 0;
        // read this way it reaches daysFrom() and is refused by name. `--days=0` is the mirror
        // case and is also present. Same rule, same reason, as SendScheduledRemindersCommand's
        // --hour and --date.
        $days = $this->option('days') === null
            ? $this->daysFrom(
                // `?? 400`, never config(..., 400) - see daysFrom() on why the second argument does
                // not defend this key.
                config('kompo-communications.reminders.prune_after_days') ?? 400,
                'kompo-communications.reminders.prune_after_days',
            )
            : $this->daysFrom($this->option('days'), '--days');

        // startOfDay(), so the window is a whole number of DAYS rather than a rolling interval
        // measured from whenever the command happened to start. Without it the cutoff slides with
        // the clock: a row survives the 03:00 cron and is deleted by a re-run at 23:00 on the same
        // day, both runs printing the same date on the line below. Midnight is also the more
        // conservative of the two readings, which is what prune()'s "$before MUST exceed the
        // longest possible sweep" asks for.
        $before = CarbonImmutable::now()->subDays($days)->startOfDay();

        $this->line("Pruning claims taken before {$before->toDateString()}.");

        $total = 0;

        foreach ($registry->all() as $reminder) {
            if ($this->option('dry-run')) {
                // Asked of the ledger rather than counted here with a copy of prune()'s WHERE
                // clause, which is how this was first written. See ReminderLedger::prunableCount().
                $count = $ledger->prunableCount($reminder->key(), $before);

                $this->line(sprintf('  %-24s %6d would be deleted', $reminder->key(), $count));

                $total += $count;

                continue;
            }

            $deleted = $ledger->prune($reminder->key(), $before);

            $this->line(sprintf('  %-24s %6d deleted', $reminder->key(), $deleted));

            $total += $deleted;
        }

        $this->newLine();
        $this->info($this->option('dry-run')
            ? "Dry run complete. {$total} claim(s) would have been deleted."
            : "Done. {$total} claim(s) deleted.");

        return self::SUCCESS;
    }

    /**
     * A retention window from an untrusted scalar - a CLI option, a published config value - or a
     * refusal naming where it came from.
     *
     * REJECTED rather than cast, and this is the worst place in the layer to cast. (int) 'forever',
     * (int) '' and (int) null are all 0; a window of 0 is a cutoff of TODAY AT MIDNIGHT, which is
     * a perfectly well-formed CarbonInterface that no check downstream can object to. prune()
     * deletes by age alone across every registered reminder, so that window empties essentially
     * the whole claims table - and the next sweep re-arms every milestone in it and re-sends the
     * entire backlog to everyone. A negative window is worse and reads as more harmless:
     * subDays(-30) is a cutoff thirty days in the FUTURE, which additionally takes the claims a
     * sweep running right now is holding. config()'s second argument does not save this either: it
     * fires only on a MISSING key, and this package ships 'prune_after_days' => 400, so a host
     * publishing the file as `'prune_after_days' => env('COMMUNICATIONS_PRUNE_DAYS')` with the env
     * unset arrives here as null, not as 400 - hence the `?? 400` at the call site.
     *
     * The floor is 1, i.e. "prune everything now" is refused rather than offered behind a
     * confirmation flag. ReminderLedger::prune() names THIS command's window as the place where
     * "$before MUST exceed the longest possible sweep" is enforced, and a cutoff of today is inside
     * every possible sweep. A window of 1 is still short enough to re-arm yesterday's sends, so the
     * floor bounds the catastrophe rather than supplying judgement; a larger floor would be a
     * number invented here with nothing to calibrate it against. An operator who genuinely means to
     * empty the table can say so in SQL, where the statement reads as the destructive act it is
     * instead of as routine cron housekeeping with a green exit code.
     *
     * Written out here rather than shared with SendScheduledRemindersCommand::hourFrom(). The two
     * produce different things - an hour in 0-23 whose range another class owns, and a day count
     * whose range is owned here because nothing downstream has one - so a helper general over both
     * would abstract a similarity of FORM rather than any observed variation. Three copies of this
     * parse now exist (sendAt, hourFrom, daysFrom). Extract the parse half - never the range half,
     * which each caller owns - the first time one of them is HARDENED, because that fix must land
     * in all three or it leaves the same hole open twice.
     */
    protected function daysFrom(mixed $value, string $source): int
    {
        // A leading '-' is ACCEPTED by the pattern and refused by the range check below, rather
        // than rejected here as "not a number": '-30' is a number, and the operator who typed it
        // needs to be told the window may not point into the future, not that they mistyped a
        // digit. hourFrom()'s pattern excludes the sign because ReminderRun owns that range.
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

            throw new \InvalidArgumentException("{$source} must be a whole number of days, got {$shown}.");
        }

        $days = (int) $value;

        if ($days < 1) {
            throw new \InvalidArgumentException(
                "{$source} must be at least 1 day, got {$days}. A window of {$days} would delete "
                . 'claims the next sweep then re-sends.',
            );
        }

        return $days;
    }
}
