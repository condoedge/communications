<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;

/**
 * The wrapper for SMS sending of the communication
 */
class DefaultLayoutSmsCommunicable extends Notification
{
    use ConvertsHtmlToPlainText;

    /**
     * Beyond ~1600 chars (10 GSM-7 segments) carriers stop concatenating and truncate mid-word,
     * and every segment is billed — worse with non-GSM characters, which halve a segment to 70.
     * Extend this layout and override the constant to change it.
     */
    protected const MAX_CONTENT_LENGTH = 1600;

    public $communication;
    public $params;

    public function __construct($communication, $params)
    {
        $this->communication = $communication;
        $this->params = $params;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['vonage'];
    }

    /**
     * Get the Vonage / SMS representation of the notification.
     */
    public function toVonage(object $notifiable): VonageMessage
    {
        $plainText = $this->toPlainText($this->communication->getParsedContent($this->params));

        // Plain '...' rather than an ellipsis character, which alone would force the whole
        // message into UCS-2.
        return (new VonageMessage)
            ->content(mb_strimwidth($plainText, 0, static::MAX_CONTENT_LENGTH, '...'));
    }
}