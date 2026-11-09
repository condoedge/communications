<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

use Condoedge\Communications\Services\Push\PushMessage;

/**
 * The wrapper for push sending of the communication: the template's subject is the notification
 * title and its content, flattened to plain text, the body.
 *
 * Reads from $params: `push_route` (where a tap leads, omitted when empty so the app decides) and
 * `push_channel` (Android notification channel). Extend it and pass the subclass as the `layout`
 * param to change any of this, as with the email and SMS layouts.
 */
class DefaultLayoutPushCommunicable
{
    use ConvertsHtmlToPlainText;

    protected const MAX_TITLE_LENGTH = 80;

    /** iOS shows about four lines on the lock screen; keep the rest for the app. */
    protected const MAX_BODY_LENGTH = 240;

    public $communication;
    public $params;

    public function __construct($communication, $params = [])
    {
        $this->communication = $communication;
        $this->params = $params;
    }

    /** @param string[] $tokens */
    public function toPush(array $tokens): PushMessage
    {
        return new PushMessage(
            tokens: $tokens,
            title: $this->title(),
            body: $this->body(),
            data: $this->data(),
            androidChannel: $this->params['push_channel'] ?? null,
        );
    }

    protected function title(): string
    {
        return $this->shorten($this->communication->getParsedTitle($this->params), static::MAX_TITLE_LENGTH);
    }

    protected function body(): string
    {
        return $this->shorten($this->communication->getParsedContent($this->params), static::MAX_BODY_LENGTH);
    }

    /** @return array<string, mixed> */
    protected function data(): array
    {
        return array_filter([
            'route' => $this->params['push_route'] ?? null,
            'communication_id' => $this->communication->id,
        ]);
    }

    protected function shorten(?string $html, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->toPlainText((string) $html)));

        return mb_strimwidth($text, 0, $max, '…');
    }
}
