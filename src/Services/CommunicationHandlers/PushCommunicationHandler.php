<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\PushCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\PushSender;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\ConvertsHtmlToPlainText;

/**
 * Push notification channel: the template's subject is the notification title and
 * its content, flattened to plain text, the body. Delivery goes through the
 * host app's PushSender binding.
 *
 * Where a tap leads: the `push_route` param, else the trigger's own pushRoute($params)
 * when it declares one, else nothing (the app decides). `push_channel` picks the Android
 * notification channel, "default" otherwise.
 */
class PushCommunicationHandler extends AbstractCommunicationHandler
{
    use ConvertsHtmlToPlainText;

    /** iOS shows about four lines on the lock screen; keep the rest for the app. */
    protected const MAX_BODY_LENGTH = 240;

    protected const MAX_TITLE_LENGTH = 80;

    public function communicableInterface()
    {
        return PushCommunicable::class;
    }

    /**
     * @param PushCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $sender = app(PushSender::class);

        $this->sendEach($communicables, function ($communicable) use ($sender, $params) {
            $tokens = array_values(array_filter($communicable->getPushTokens()));

            if (!$tokens) {
                return 'no-push-device';
            }

            $perRecipientParams = ContextEnhancer::setCommunicable($communicable)->getEnhancedContext($params);

            $title = $this->shorten($this->communication->getParsedTitle($perRecipientParams), static::MAX_TITLE_LENGTH);
            $body = $this->shorten($this->communication->getParsedContent($perRecipientParams), static::MAX_BODY_LENGTH);

            $data = array_filter([
                'route' => $this->routeFor($params),
                'communication_id' => $this->communication->id,
            ]);

            $sender->send(array_map(fn ($token) => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'channelId' => $params['push_channel'] ?? 'default',
            ], $tokens));

            return null;
        });
    }

    protected function routeFor(array $params): ?string
    {
        if (!empty($params['push_route'])) {
            return $params['push_route'];
        }

        $trigger = $params['trigger_instance'] ?? null;

        return $trigger && method_exists($trigger, 'pushRoute') ? $trigger->pushRoute($params) : null;
    }

    protected function shorten(?string $html, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->toPlainText((string) $html)));

        return mb_strimwidth($text, 0, $max, '…');
    }
}
