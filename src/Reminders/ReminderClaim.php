<?php

namespace Condoedge\Communications\Reminders;

/**
 * Why a claim did or did not succeed.
 *
 * A bool collapses two operationally different situations — an in-flight run owns it, versus it
 * completed hours ago. The first wants a console line saying so; the second is the ordinary steady
 * state. Collapsing them is how a bad interleaving stays invisible.
 */
enum ReminderClaim
{
    case CLAIMED;
    case HELD_BY_ANOTHER_RUN;
    case ALREADY_HANDLED;
}
