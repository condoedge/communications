<?php

namespace Condoedge\Communications\Tests\Stubs;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\Support\RemindsAbout;

/**
 * A plain event object. Not a CommunicableEvent: the sweeper calls event() and never inspects
 * what it dispatched, which is exactly the decoupling being tested.
 */
class DeadlineApproaching
{
    use RemindsAbout;

    public function __construct(
        public $deadline,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
    ) {
        $this->remindsAbout('test-deadline', $offset, $anchorOn, $deadline->getKey());
    }

    public function getParams(): array
    {
        return array_merge($this->reminderParams(), [
            'deadline' => $this->deadline,
        ]);
    }
}
