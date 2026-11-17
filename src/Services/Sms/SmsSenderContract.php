<?php

namespace Condoedge\Communications\Services\Sms;

/**
 * Delivers text messages for a host that sends them its own way, to keep its own record of each
 * one for instance. Without a binding the SMS channel notifies the phone number directly.
 */
interface SmsSenderContract
{
    /** Throwing marks this recipient FAILED in the send log; the other recipients still go out. */
    public function send(SmsMessage $message): void;
}
