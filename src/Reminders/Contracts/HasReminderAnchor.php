<?php

namespace Condoedge\Communications\Reminders\Contracts;

use Carbon\CarbonInterface;

/**
 * A host model that can tell a reminder which of its dates to count from.
 */
interface HasReminderAnchor
{
    public function reminderAnchorDate(string $reminderKey): ?CarbonInterface;
}
