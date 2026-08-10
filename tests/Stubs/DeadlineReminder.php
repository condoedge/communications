<?php

namespace Condoedge\Communications\Tests\Stubs;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\AbstractDateAnchoredReminder;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The package's own reminder, used to test the base class without any host's domain.
 *
 * Deliberately minimal: one filter (a reachable address), one stored anchor column, one event.
 */
class DeadlineReminder extends AbstractDateAnchoredReminder
{
    public function key(): string
    {
        return 'test-deadline';
    }

    public function description(): string
    {
        return 'Test deadlines approaching';
    }

    /** @return ReminderOffset[] */
    protected function offsets(): array
    {
        return [
            ReminderOffset::before(7),
            ReminderOffset::onTheDay(),
            ReminderOffset::after(7),
        ];
    }

    protected function anchorExpression()
    {
        return 'due_on';
    }

    protected function subjectQuery(ReminderScope $scope): Builder
    {
        // A subject with no address has nobody to tell, so it is filtered out in SQL rather than
        // claimed and released one row at a time.
        return Deadline::query()->whereNotNull('email');
    }

    public function eventFor(
        Model $subject,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        ReminderScope $scope,
    ): ?object {
        return new DeadlineApproaching($subject, $offset, $anchorOn);
    }
}
