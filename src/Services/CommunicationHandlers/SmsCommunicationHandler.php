<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\SmsCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutSmsCommunicable;
use Condoedge\Communications\Services\Sms\SmsMessage;
use Condoedge\Communications\Services\Sms\SmsSenderContract;

/**
 * SMS channel. What the message says is the layout's business (DefaultLayoutSmsCommunicable, or the
 * `layout` param); a host that binds SmsSenderContract delivers it itself.
 */
class SmsCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return SmsCommunicable::class;
    }

    // NOTIFICATION

    /**
     * @param SmsCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $layout = $this->layoutFrom($params, DefaultLayoutSmsCommunicable::class);
        $sender = app()->bound(SmsSenderContract::class) ? app(SmsSenderContract::class) : null;

        $this->sendEach($communicables, function ($communicable, $position) use ($layout, $params, $sender) {
            $phone = $communicable->getPhone();

            if (!$phone) {
                return 'no-phone-number';
            }

            $perRecipientParams = $this->paramsFor($communicable, $params);

            if (isset($this->recipientRowIds[$position])) {
                $perRecipientParams['communication_sending_recipient_id'] = $this->recipientRowIds[$position];
            }

            $notification = new $layout($this->communication, $perRecipientParams);

            if ($sender) {
                $sender->send(new SmsMessage((string) $phone, static::contentOf($notification), $perRecipientParams));

                return null;
            }

            if ($locale = self::recipientLocale($communicable)) {
                $notification = $notification->locale($locale);
            }

            Notification::route('vonage', $phone)->notify($notification);

            return null;
        });
    }

    protected static function contentOf($notification): string
    {
        return method_exists($notification, 'content')
            ? $notification->content()
            : (string) $notification->toVonage(new AnonymousNotifiable)->content;
    }
}
