<?php

namespace Condoedge\Communications\Reminders\Support;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\ReminderOffset;

/**
 * The parts of a reminder event that were identical in all five of SISC's event classes.
 *
 * getIdempotencyKey() moves the event from the package's implicit 30-second content bucket into
 * the explicit 10-minute tier (CommunicationTriggeredListener::IDEMPOTENCY_TTL_MINUTES). That
 * guards a QUEUE RETRY. It does NOT replace the claim ledger, which is the only thing standing
 * between a standing date predicate and a nightly re-send — deleting the ledger because "the event
 * is idempotent now" would break everything quietly.
 */
trait RemindsAbout
{
    protected ReminderOffset $reminderOffset;
    protected CarbonInterface $reminderAnchorOn;
    protected string $reminderKey;
    protected string|int $reminderSubjectKey;

    protected function remindsAbout(
        string $reminderKey,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        // Required rather than optional-defaulting-to-null, because omitting it fails OPEN and
        // silently: idempotencyKeyFor()'s explicit branch drops the recipient signature its implicit
        // branch folds in, so without the subject forty invoices due the same day claim one key and
        // thirty-nine sends disappear behind an info-level "Skipping duplicate communication
        // dispatch". One sweep is N separate dispatches, one event per subject, so (reminder,
        // offset, anchor date, class) alone is the SAME string for every one of them. A default
        // would let that authoring mistake produce a well-formed key the listener happily accepts.
        string|int $subjectKey,
    ): void {
        $this->reminderKey = $reminderKey;
        $this->reminderOffset = $offset;
        $this->reminderAnchorOn = $anchorOn;
        $this->reminderSubjectKey = $subjectKey;
    }

    public function getIdempotencyKey(): string
    {
        // Five components. The fifth, the subject key, is the load-bearing one — see remindsAbout().
        return implode(':', [
            $this->reminderKey,
            $this->reminderOffset->storageKey(),
            $this->reminderAnchorOn->toDateString(),
            static::class,
            $this->reminderSubjectKey,
        ]);
    }

    /**
     * Merge into getParams(). The three keys every reminder template may use.
     *
     * They are a DATA FORMAT owned by the HOST, not private vocabulary of this layer. SISC
     * registers all three as pickable editor variables in config/communication-variables.php and
     * defines their render callbacks in config/communication-replacers.php, and templates already
     * authored interpolate them by name. Renaming 'deadline_date' to match this layer's own
     * 'anchor' vocabulary (anchorFor / anchorExpression / anchor_on) leaves the suite green and
     * blanks that variable in every live template. A new host adopting reminders registers these
     * same three names, not names of its own.
     */
    protected function reminderParams(): array
    {
        return [
            'deadline_date' => $this->reminderAnchorOn->format('Y-m-d'),
            'days_left'     => (string) $this->reminderOffset->daysLeft(),
            'days_overdue'  => (string) $this->reminderOffset->daysOverdue(),
        ];
    }
}
