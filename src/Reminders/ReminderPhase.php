<?php

namespace Condoedge\Communications\Reminders;

/**
 * Direction is declared, never inferred from a sign.
 */
enum ReminderPhase: string
{
    case BEFORE = 'b';
    case ON     = 'd';
    case AFTER  = 'a';
}
