<?php

namespace Condoedge\Communications\Reminders;

use Condoedge\Communications\Reminders\Contracts\ScheduledReminder;
use Illuminate\Support\Collection;

/**
 * The registered reminders, resolved and checked.
 *
 * Registering a reminder is adding a class to config('kompo-communications.reminders.scheduled').
 * The once-only guarantee, the scheduling and the dry run come from the runner; this is only the
 * lookup, plus the two checks that stop a registration mistake from being invisible.
 *
 * The contract is VALIDATED AT BOOT, THEN USED: CondoedgeCommunicationServiceProvider::verifyReminders()
 * calls validate() from loadListeners(), so a bad class name is a deploy-time refusal naming the class,
 * not a BindingResolutionException out of all() at 3am. Duplicating the checks inside all() would buy a
 * nicer message for a state the boot has already refused to start in, and would re-run class_implements()
 * on every sweep of every reminder.
 */
class ReminderRegistry
{
    /** @return Collection<int, ScheduledReminder> */
    public function all(?string $only = null): Collection
    {
        // The [] default here is for symmetry with validate() only — collect(null) is already an
        // empty collection, so this line survives the key being absent either way. The default that
        // actually defends something is the one in validate(); do not read this one as evidence
        // that the config key is guaranteed to exist.
        return collect(config('kompo-communications.reminders.scheduled', []))
            ->map(fn ($class) => app($class))
            // `=== null`, not truthiness: null alone means "no filter". A falsy test folds '' and '0'
            // into "no filter", so an operator who types --only= to NARROW the sweep gets the full one
            // and every reminder actually sends. Same rule as ReminderScope::label().
            ->filter(fn ($reminder) => $only === null || $reminder->key() === $only)
            ->values();
    }

    /**
     * Fails closed at boot like verifyCommunicationTriggers(), except that reading key() forces every
     * registered class to be CONSTRUCTED at boot, which that check never does. A reminder whose
     * constructor resolves something a later provider binds therefore fatals at install time, not at
     * sweep time — reminder constructors take no dependencies.
     *
     * A duplicate key is the one worth spelling out: two reminders sharing a key() read each
     * other's claim rows, so each sees the other's sends as its own and both fall silent. Nothing
     * downstream would report it.
     */
    public function validate(): void
    {
        $keys = [];

        // The [] default is load-bearing, unlike its twin in all(). The block DOES now ship, and
        // that is NOT a reason to remove this: mergeConfigFrom merges one level deep, so a host that
        // published this config file BEFORE the reminders block existed - or published it and then
        // deleted or nulled the block - replaces the package's array wholesale and reaches this line
        // with null, not with []. Bare PHP only warns on foreach over null, which is why this reads
        // as harmless; under Laravel's error handler that warning is thrown as an ErrorException.
        // Since this method runs at boot, dropping the default takes such a host down at install
        // time rather than at use time.
        foreach (config('kompo-communications.reminders.scheduled', []) as $class) {
            // Checked first, because class_implements() answers false for a class it cannot load and
            // in_array() would then TypeError at boot, naming neither the class nor the config key.
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
