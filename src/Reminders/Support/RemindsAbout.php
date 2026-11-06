<?php

namespace Condoedge\Communications\Reminders\Support;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\ReminderOffset;

/**
 * The idempotency key and the template variables every reminder event shares.
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
        string|int $subjectKey,
    ): void {
        $this->reminderKey = $reminderKey;
        $this->reminderOffset = $offset;
        $this->reminderAnchorOn = $anchorOn;
        $this->reminderSubjectKey = $subjectKey;
    }

    public function getIdempotencyKey(): string
    {
        return implode(':', [
            $this->reminderKey,
            $this->reminderOffset->storageKey(),
            $this->reminderAnchorOn->toDateString(),
            static::class,
            $this->reminderSubjectKey,
        ]);
    }

    protected function reminderParams(): array
    {
        return [
            'deadline_date' => $this->reminderAnchorOn->format('Y-m-d'),
            'days_left'     => (string) $this->reminderOffset->daysLeft(),
            'days_overdue'  => (string) $this->reminderOffset->daysOverdue(),
        ];
    }
}
