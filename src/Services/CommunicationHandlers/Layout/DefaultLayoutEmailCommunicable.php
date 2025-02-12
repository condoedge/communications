<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

use Illuminate\Mail\Mailable;

/**
 * The wrapper for email sending of the communication
 */
class DefaultLayoutEmailCommunicable extends Mailable
{
    public $communication;
    public $params;

    public function __construct($communication, $params = [])
    {
        $this->communication = $communication;
        $this->params = $params;
    }

    public function build()
    {
        return $this->subject($this->communication->subject)
                    ->markdown('condoedge-comms::emails.communication-layout', [
                        'content' => $this->communication->getParsedContent($this->params),
                    ]);
    }
}