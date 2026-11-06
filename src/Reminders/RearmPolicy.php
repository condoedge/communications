<?php

namespace Condoedge\Communications\Reminders;

/**
 * What makes a second reminder for the same subject and offset legitimate.
 */
enum RearmPolicy
{
    case ONCE_EVER;

    case PER_ANCHOR_DATE;
}
