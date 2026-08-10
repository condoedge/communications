<?php

namespace Condoedge\Communications\Reminders;

/**
 * What makes a SECOND reminder for the same subject and offset legitimate.
 */
enum RearmPolicy
{
    /**
     * One claim for all time. A moved anchor never re-arms.
     *
     * The DEFAULT, because it is what SISC does today: its unique index is
     * (reminder_key, milestone, subject_type, subject_id), with no date in it. A port must be able
     * to reproduce existing behaviour exactly by changing nothing, and PER_ANCHOR_DATE does not —
     * it would re-send to everyone whose anchor value has ever been rewritten, including by a data
     * backfill nobody thought of as a business change.
     */
    case ONCE_EVER;

    /**
     * The anchor's VALUE is part of the identity, so moving it re-arms. Opt in when a human moving
     * that date is a business decision that people are entitled to be re-told about: a campaign
     * end pushed back, an invoice due date extended, an event postponed.
     *
     * Do NOT opt in when the anchor is derived rather than stored. SISC's background-check expiry
     * is COALESCE(renewal_date, status_at + N years) over the latest approved check: a one-off
     * backfill of renewal_date would re-arm every volunteer in the install on the same night.
     */
    case PER_ANCHOR_DATE;
}
