<?php

namespace Condoedge\Communications\Reminders\Contracts;

use Carbon\CarbonInterface;

/**
 * A host model that can tell a reminder which of its dates to count from.
 *
 * The reminder KEY is passed rather than assumed, because one model routinely anchors several
 * reminders: a campaign is "3 days before start" for one and "7 days before end" for another, and
 * a single reminderAnchorDate() with no argument would force two models or a flag column.
 *
 * Returning null means "there is no date here right now" and is a first-class answer, not an
 * error: a relation was deleted between the query and the send, or the column is genuinely empty.
 * The runner skips the record rather than sending an email with a blank date in it.
 *
 * This is the read side only. What the SELECT matches on is the reminder's anchorExpression(),
 * which is SQL and cannot live here.
 */
interface HasReminderAnchor
{
    public function reminderAnchorDate(string $reminderKey): ?CarbonInterface;
}
