<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

use Illuminate\Mail\Mailable;

class DefaultLayoutEmailCommunicable extends Mailable
{
    public $communication;
    public $params;

    public function __construct($communication, $params = [])
    {
        $this->communication = $communication;
    }

    public function build()
    {
        return $this->subject($this->communication->subject)
                    ->markdown('emails.communication-layout', [
                        'content' => $this->communication->getParsedContent($this->params),
                    ]);
    }    
}