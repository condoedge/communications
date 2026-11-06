<?php

namespace Condoedge\Communications\Reminders;

use Condoedge\Communications\Reminders\Contracts\ScheduledReminder;
use Illuminate\Support\Collection;

/**
 * The registered reminders, resolved and checked.
 */
class ReminderRegistry
{
    /** @return Collection<int, ScheduledReminder> */
    public function all(?string $only = null): Collection
    {
        return collect(config('kompo-communications.reminders.scheduled', []))
            ->map(fn ($class) => app($class))
            ->filter(fn ($reminder) => $only === null || $reminder->key() === $only)
            ->values();
    }

    public function validate(): void
    {
        $keys = [];

        foreach (config('kompo-communications.reminders.scheduled', []) as $class) {
            if (!class_exists($class)) {
                throw new \InvalidArgumentException("The reminder class {$class} does not exist.");
            }

            if (!in_array(ScheduledReminder::class, class_implements($class), true)) {
                throw new \InvalidArgumentException(
                    "The reminder class {$class} must implement " . ScheduledReminder::class . '.',
                );
            }

            $key = app($class)->key();

            if (isset($keys[$key])) {
                throw new \InvalidArgumentException(
                    "Two reminders share the key '{$key}': {$keys[$key]} and {$class}. "
                    . 'They would read each other\'s claim rows and both go silent.',
                );
            }

            $keys[$key] = $class;
        }
    }
}
