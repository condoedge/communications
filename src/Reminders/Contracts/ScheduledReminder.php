<?php

namespace Condoedge\Communications\Reminders\Contracts;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderRun;
use Condoedge\Communications\Reminders\ReminderScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A date-anchored reminder.
 */
interface ScheduledReminder
{
    public function key(): string;

    public function description(): string;

    /** @return iterable<int, */
    public function scopes(ReminderRun $run): iterable;

    /** @return iterable<int, */
    public function dueAt(ReminderScope $scope, ReminderOffset $offset, ReminderRun $run): iterable;

    public function anchorFor(Model $subject): ?CarbonInterface;

    public function rearmKeyFor(?CarbonInterface $anchorOn): string;

    public function eventFor(
        Model $subject,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        ReminderScope $scope,
    ): ?object;
}
