<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\ChannelAware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

abstract class AbstractCommunicationHandler
{
    protected CommunicationTemplate $communication;
    protected $type;
    protected DeliveryReport $report;

    public function __construct(?CommunicationTemplate $communication, ?CommunicationType $type)
    {
        $this->communication = $communication ?? new CommunicationTemplate;
        $this->type = $type;
        $this->report = new DeliveryReport;
    }

    /**
     * Return the interface that the communicables should implement
     * @return \Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable
     */
    abstract public function communicableInterface();

    public function communicableEventInterface()
    {
        return CommunicableEvent::class;
    }

    // NOTIFICATION

    /**
     * The concrete implementation of the notification method to send the communication to the communicables
     * @param array<\Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable> $communicables
     * @param array<string, mixed> $params
     * @return void
     */
    abstract public function notifyCommunicables(array $communicables, $params = []);

    /**
     * Filter the communicables and notify them using `notifyCommunicables` method.
     *
     * Returns what actually happened per recipient. Recipients dropped by the channel filter are
     * reported SKIPPED rather than silently discarded, so they are never later stamped SENT.
     *
     * CONTRACT: outcomes are keyed by each recipient's POSITION in the collection, which is how
     * CommunicationSending matches them back to the rows it wrote. An implementation of
     * notifyCommunicables() must therefore iterate the array it receives WITHOUT reindexing it —
     * an array_values()/usort() in a handler silently misattributes every outcome.
     *
     * @param array|\Illuminate\Database\Eloquent\Collection $communicables
     * @param mixed $params
     */
    final public function notify(array|Collection $communicables, $params = []): DeliveryReport
    {
        $this->report = new DeliveryReport;
        $communicableInterface = $this->communicableInterface();

        // values() first so positions are 0..n-1, matching the rows CommunicationSending wrote.
        // filter() preserves keys, so the surviving entries keep their original position.
        $communicables = collect($communicables)->values()->filter(function ($c, $position) use ($communicableInterface) {
            if (!($c instanceof $communicableInterface)) {
                Log::warning('Communicable does not implement the required interface: ' . $communicableInterface);
                $this->report->skipped($position, 'does-not-implement-' . class_basename($communicableInterface));

                return false;
            }

            if ($c instanceof ChannelAware && !$c->acceptsChannel($communicableInterface)) {
                $this->report->skipped($position, 'channel-not-accepted'); // intentional suppression — no warning

                return false;
            }

            return true;
        });

        $this->notifyCommunicables($communicables->all(), $params);

        return $this->report;
    }

    /**
     * Deliver to each recipient in isolation: one failure is recorded against that recipient only
     * and the rest of the list still goes out. Without this a throw partway through abandons every
     * remaining recipient while the already-delivered ones get marked failed.
     *
     * The callback returns null when it delivered, or a short reason string when it chose not to
     * (no phone number, opted out). $communicables must keep its original positional keys.
     */
    protected function sendEach(array $communicables, callable $send): void
    {
        foreach ($communicables as $position => $communicable) {
            try {
                $skipReason = self::withRecipientLocale($communicable, fn () => $send($communicable));

                $skipReason === null
                    ? $this->report->sent($position)
                    : $this->report->skipped($position, is_string($skipReason) ? $skipReason : null);
            } catch (\Throwable $e) {
                Log::error('Communication delivery failed for a recipient', [
                    'communication_id' => $this->communication->id,
                    'channel' => $this->type?->value,
                    'error' => $e->getMessage(),
                ]);

                $this->report->failed($position, $e->getMessage());
            }
        }
    }

    /** Record one status for every listed recipient, for channels that cannot report per recipient. */
    protected function reportAll(array $communicables, CommunicationSendingRecipientStatus $status, ?string $detail = null): void
    {
        foreach (array_keys($communicables) as $position) {
            $this->report->record($position, $status, $detail);
        }
    }

    // COMMUNICATION SAVING

    /**
     * Save the communication with the given attributes
     * @param number $groupId
     * @param array<string, mixed> $attributes
     * @return void
     */
    public function save($groupId = null, $attributes = [])
    {
        $this->communication->type = $this->type;
        $this->communication->template_group_id = $groupId;

        $this->communication->subject = $attributes['subject'] ?? null;
        $this->communication->content = $attributes['content'] ?? null;

        if ($this->validToSave($attributes) || $this->communication->id) {
            $this->communication->is_draft = $this->isDraft($attributes) ? 1 : 0;

            $this->communication->save();
        }

        request()->replace([]);
    }

    /**
     * Return the form inputs for the communication to integrate into the `getForm` method
     * @return array<\Kompo\Elements\Element>|\Kompo\Elements\Element
     */
    public function formInputs($trigger = null, $context = [])
    {
        $attrs = $this->communication->getAttributes();

        return [
            _Translatable('Subject')->name('subject', false)->default(json_decode($attrs['subject'] ?? '{}')),
            _EnhancedEditor('Content')->name('content', false)->default(json_decode($attrs['content'] ?? '{}'))
                ->filterVarsToThisIds($trigger::validVariablesIds(context: $context)),
        ];
    }

    /**
     * Return the form for the communication
     * @return \Kompo\Rows
     */
    final public function getForm($trigger = null, $context = [])
    {
        if ($trigger && !$this->typeIsValidToTrigger($trigger)) {
            return _Html('The selected communication type is not valid for this trigger.')->class('text-center text-red-500');
        }

        return _Rows(
            _Rows($this->formInputs($trigger, $context)),

            _Hidden()->name('previous_communication_type', false)->value($this->type),
        );
    }

    // VALIDATION

    protected function typeIsValidToTrigger($trigger)
    {
        $implements = class_implements($trigger);

        return in_array($this->communicableEventInterface(), $implements);
    }

    /**
     * Check if the communication is valid to save. It should also return true if the communication has a valid data to be a draft 
     * @param mixed $attributes
     * @return bool
     */
    public function validToSave($attributes = [])
    {
        return (bool) collect($this->requiredAttributes())->first(function ($attribute) use ($attributes) {
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    /**
     * Return if the communication is a draft keeping in mind the required attributes
     * @param mixed $attributes
     * @return bool
     */
    public function isDraft($attributes = [])
    {
        return !collect($this->requiredAttributes())->every(function ($attribute) use ($attributes) {
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    /**
     * Return the required attributes for the communication to be valid (not draft)
     * @return string[]
     */
    protected function requiredAttributes()
    {
        return ['subject', 'content'];
    }

    // LOCALE

    /**
     * Run $callback with the locale temporarily switched to the recipient's preferredLocale().
     * Restores the previous locale even if $callback throws.
     *
     * Public static so it can be invoked from outside the handler hierarchy
     * (e.g. from NotificationTemplate::sendNotification).
     *
     * @param mixed $communicable
     * @param callable $callback
     * @return mixed
     */
    public static function withRecipientLocale($communicable, callable $callback)
    {
        if (!$communicable instanceof \Illuminate\Contracts\Translation\HasLocalePreference) {
            return $callback();
        }
        $locale = $communicable->preferredLocale();
        if (!$locale) {
            return $callback();
        }
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
