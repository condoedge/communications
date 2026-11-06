<?php

namespace Condoedge\Communications\Reminders\Console;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Reminders\ReminderRegistry;
use Illuminate\Console\Command;

/**
 * Deletes claim rows old enough that nobody will ask about them again.
 */
class PruneReminderClaimsCommand extends Command
{
    protected $signature = 'communications:prune-reminder-claims
                            {--dry-run : Report what would be deleted and delete nothing}
                            {--days= : Override the retention window, in days}';

    protected $description = 'Delete reminder claim rows older than the configured retention window.';

    public function handle(ReminderRegistry $registry, ReminderLedger $ledger): int
    {
        $days = $this->option('days') === null
            ? $this->daysFrom(
                config('kompo-communications.reminders.prune_after_days') ?? 400,
                'kompo-communications.reminders.prune_after_days',
            )
            : $this->daysFrom($this->option('days'), '--days');

        $before = CarbonImmutable::now()->subDays($days)->startOfDay();

        $this->line("Pruning claims taken before {$before->toDateString()}.");

        $total = 0;

        foreach ($registry->all() as $reminder) {
            if ($this->option('dry-run')) {
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

    protected function daysFrom(mixed $value, string $source): int
    {
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
