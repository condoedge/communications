<?php

namespace Condoedge\Communications\Reminders;

/**
 * Why a claim did or did not succeed.
 */
enum ReminderClaim
{
    case CLAIMED;
    case HELD_BY_ANOTHER_RUN;
    case ALREADY_HANDLED;
}
