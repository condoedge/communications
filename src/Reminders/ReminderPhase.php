<?php

namespace Condoedge\Communications\Reminders;

/**
 * Direction is declared, never inferred from a sign.
 *
 * SISC inferred it: ReminderMilestone::after(0) produced daysBefore = 0, isAfterTheDeadline() then
 * returned false, and the "after" milestone silently dispatched the "before" event under a label
 * ('j+0') distinct from before(0)'s ('j-0') — two ledger identities for one calendar day. Nothing
 * configures 0 today, which is exactly why it survived.
 *
 * The backing values are the first character of every offset storage key, so they are a data
 * format: never rename them.
 */
enum ReminderPhase: string
{
    case BEFORE = 'b';
    case ON     = 'd';
    case AFTER  = 'a';
}
