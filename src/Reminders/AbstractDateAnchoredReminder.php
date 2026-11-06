<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\Contracts\HasReminderAnchor;
use Condoedge\Communications\Reminders\Contracts\ScheduledReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a date-anchored reminder needs that is identical across every one of them.
 */
abstract class AbstractDateAnchoredReminder implements ScheduledReminder
{
    abstract public function key(): string;

    abstract public function description(): string;

    /** @return ReminderOffset[] */
    abstract protected function offsets(): array;

    /** @return string|\Illuminate\Contracts\Database\Query\Expression */
    abstract protected function anchorExpression();

    abstract protected function subjectQuery(ReminderScope $scope): Builder;

    abstract public function eventFor(
        Model $subject,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        ReminderScope $scope,
    ): ?object;

    public function dueAt(ReminderScope $scope, ReminderOffset $offset, ReminderRun $run): iterable
    {
        return $this->subjectQuery($scope)
            ->whereDate($this->anchorExpression(), '=', $offset->targetDateFrom($run->today)->toDateString())
            ->cursor();
    }

    public function anchorFor(Model $subject): ?CarbonInterface
    {
        return $subject instanceof HasReminderAnchor
            ? $subject->reminderAnchorDate($this->key())
            : null;
    }

    protected function rearmPolicy(): RearmPolicy
    {
        return RearmPolicy::ONCE_EVER;
    }

    public function rearmKeyFor(?CarbonInterface $anchorOn): string
    {
        return match ($this->rearmPolicy()) {
            RearmPolicy::ONCE_EVER       => 'once',
            RearmPolicy::PER_ANCHOR_DATE => $anchorOn?->toDateString() ?? 'none',
        };
    }

    public function scopes(ReminderRun $run): iterable
    {
        return [new ReminderScope(null, $this->dedupe($this->offsets()), $this->sendAt())];
    }

    protected function sendAt(): string
    {
        $hour = config('kompo-communications.reminders.default_hour') ?? 9;

        if (!is_int($hour) && !(is_string($hour) && preg_match('/^\d+$/', $hour))) {
            $shown = is_scalar($hour) ? var_export($hour, true) : get_debug_type($hour);

            throw new \InvalidArgumentException(
                "kompo-communications.reminders.default_hour must be an integer hour, got {$shown}."
            );
        }

        return sprintf('%02d:00', (int) $hour);
    }

    /**
     * @param  ReminderOffset[]  $offsets
     * @return ReminderOffset[]
     */
    protected function dedupe(array $offsets): array
    {
        $seen = [];

        foreach ($offsets as $offset) {
            $seen[$offset->storageKey()] ??= $offset;
        }

        uasort($seen, fn ($a, $b) => $b->signedDays() <=> $a->signedDays());

        return array_values($seen);
    }
}
