<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\PushCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutPushCommunicable;
use Condoedge\Communications\Services\Push\PushSenderContract;

/**
 * Push notification channel. What the notification says is the layout's business
 * (DefaultLayoutPushCommunicable, or the `layout` param); delivery is the host's PushSenderContract.
 *
 * Where a tap leads: the `push_route` param, else the trigger's own pushRoute($params) when it
 * declares one, else nothing (the app decides).
 */
class PushCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return PushCommunicable::class;
    }

    /** Push needs the host to bind a sender; the package ships none. */
    public function isConfigured(): bool
    {
        return app()->bound(PushSenderContract::class);
    }

    /**
     * @param PushCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        // CommunicationType::sendable() keeps the dispatch path from ever reaching here unbound;
        // this covers a caller that went to the handler directly.
        if (!$this->isConfigured()) {
            $this->reportAll($communicables, CommunicationSendingRecipientStatus::SKIPPED, 'no-push-sender');

            return;
        }

        $sender = app(PushSenderContract::class);
        $layout = $this->layoutFrom($params, DefaultLayoutPushCommunicable::class);

        $this->sendEach($communicables, function ($communicable) use ($sender, $layout, $params) {
            $tokens = array_values(array_filter($communicable->getPushTokens()));

            if (!$tokens) {
                return 'no-push-device';
            }

            $perRecipientParams = $this->paramsFor($communicable, $params);
            $perRecipientParams['push_route'] = $this->routeFor($perRecipientParams);

            $sender->send((new $layout($this->communication, $perRecipientParams))->toPush($tokens));

            return null;
        });
    }

    /** Resolved per recipient, so a trigger can deep-link each one to their own resource. */
    protected function routeFor(array $perRecipientParams): ?string
    {
        if (!empty($perRecipientParams['push_route'])) {
            return $perRecipientParams['push_route'];
        }

        $trigger = $perRecipientParams['trigger_instance'] ?? null;

        return $trigger && method_exists($trigger, 'pushRoute') ? $trigger->pushRoute($perRecipientParams) : null;
    }
}
