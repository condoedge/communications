<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;

/**
 * The wrapper for SMS sending of the communication
 */
class DefaultLayoutSmsCommunicable extends Notification
{
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
        $plainText = strip_tags($this->communication->getParsedContent($this->params));

        return (new VonageMessage)
            ->content($plainText);
    }
}