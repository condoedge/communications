<?php

namespace Condoedge\Communications\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Illuminate\Validation\ValidationException;
use Kompo\Auth\Facades\NotificationModel;

/**
 * How a system message leaves the modal: a Close button that only goes through once the
 * "I have read the message" box is ticked. A real form submit on purpose — it is the only request
 * whose 422 Kompo paints on the field, and the refusal is enforced here, not in the browser.
 */
class ModalNotificationAcknowledgeForm extends Form
{
    protected $notificationId;
    protected $isLast;

    public function created()
    {
        $this->notificationId = $this->prop('notification_id');
        $this->isLast = (bool) $this->prop('is_last');
    }

    public function handle()
    {
        if (!request('acknowledged')) {
            throw ValidationException::withMessages([
                'acknowledged' => __('communications.confirm-you-have-read-the-message'),
            ]);
        }

        $notification = NotificationModel::findOrFail($this->notificationId);

        if ($notification->user_id !== auth()->id()) {
            abort(403, __('auth-you-dont-have-access-to-this-notification'));
        }

        $notification->markSeen();
    }

    public function render()
    {
        return _Rows(
            _Checkbox('communications.i-have-read-the-message')->name('acknowledged', false)->class('!mb-2'),
            _FlexEnd(
                // Closing the last pending row has nothing left to show, so it closes the modal instead.
                // modal().close(), not closeModal(): that one only reaches the nearest komponent (the list).
                _SubmitButton('communications.close')
                    ->when($this->isLast, fn ($e) => $e->run('({modal}) => modal().close()'))
                    ->when(!$this->isLast, fn ($e) => $e->browse(ModalNotificationsList::ID)),
            ),
        )->class('mt-4');
    }
}
