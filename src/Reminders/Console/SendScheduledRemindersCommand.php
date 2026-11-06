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
                $unschedulable++;

                Log::error('Scheduled reminder failed entirely', [
                    'reminder' => $reminder->key(),
                    'exception' => $e,
                ]);

                $this->error("  {$reminder->key()}: failed - see the log");
            }
        }

        $attempted = $completed + $brokenOffsets;

        $deadSweep = $attempted > 0 && $completed === 0;
        $nothingCanSchedule = $attempted === 0 && $unschedulable === $reminders->count();

        return $deadSweep || $nothingCanSchedule ? self::FAILURE : self::SUCCESS;
    }

    protected function buildRun(): ReminderRun
    {
        $hour = $this->option('hour') === null ? null : $this->hourFrom($this->option('hour'), '--hour');

        $date = $this->option('date');

        if ($date !== null) {
            if (trim($date) === '') {
                throw new \InvalidArgumentException('--date must be a Y-m-d date, got an empty string.');
            }

            $tz = config('kompo-communications.reminders.timezone') ?? config('app.timezone');

            return new ReminderRun(
                CarbonImmutable::parse($date, $tz)->startOfDay(),
                $hour ?? $this->hourFrom(
                    config('kompo-communications.reminders.default_hour') ?? 9,
                    'kompo-communications.reminders.default_hour',
                ),
                (bool) $this->option('dry-run'),
            );
        }

        return ReminderRun::now((bool) $this->option('dry-run'), null, $hour);
    }

    protected function hourFrom(mixed $value, string $source): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            return (int) $value;
        }

        $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        throw new \InvalidArgumentException("{$source} must be an integer hour, got {$shown}.");
    }

    /** @return array{0: */
    protected function runReminder(ScheduledReminder $reminder, ReminderLedger $ledger, ReminderRun $run): array
    {
        $header = false;
        $completed = 0;
        $broken = 0;

        foreach ($reminder->scopes($run) as $scope) {
            if ($scope->hour() !== $run->hour) {
                continue;
            }

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

            if ($anchorOn === null) {
                $noAnchor++;
                continue;
            }

            $rearmKey = $reminder->rearmKeyFor($anchorOn);

            if ($run->dryRun) {
                $ledger->alreadyHandled($reminder->key(), $offset, $rearmKey, $subject) ? $skipped++ : $sent++;
                continue;
            }

            $claim = $ledger->claim(
                $reminder->key(),
                $offset,
                $rearmKey,
                $subject,
                $anchorOn,
                null,
                $scope->teamId,
            );

            if ($claim === ReminderClaim::HELD_BY_ANOTHER_RUN) {
                $held++;
                continue;
            }

            if ($claim === ReminderClaim::ALREADY_HANDLED) {
                $skipped++;
                continue;
            }

            try {
                $event = $reminder->eventFor($subject, $offset, $anchorOn, $scope);

                if ($event === null) {
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
                Log::error('Scheduled reminder failed to dispatch', [
                    'reminder' => $reminder->key(),
                    'offset' => $offset->storageKey(),
                    'subject' => $subject->getMorphClass() . '#' . $subject->getKey(),
                    'exception' => $e,
                ]);

                $this->error("  {$offset->storageKey()}: {$subject->getMorphClass()}#{$subject->getKey()} failed - see the log");
            }
        }

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
