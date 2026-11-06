<?php

namespace Condoedge\Communications\Components;

use Kompo\Auth\Monitoring\NotificationsList as PackageList;

/**
 * The modal third of the notifications split: rows flagged `is_modal` at write time (a trigger
 * returns `is_modal => true` from getParams()), meant to be opened in a modal by the host page.
 *
 * A row also flagged `is_system_message` has to be acknowledged: its card offers no dismiss, only
 * ModalNotificationAcknowledgeForm (tick the box, then Close), and `seen_at` is its read record. Rendering is delegated to the
 * notification model when it defines `notificationModalCard()`, as BannersList does.
 */
class ModalNotificationsList extends PackageList
{
    /** ModalNotificationAcknowledgeForm re-browses this list once a message is acknowledged. */
    public const ID = 'modal-notifications-list';

    public $id = self::ID;

    public $hasPagination = false;
    public $itemsWrapperStyle = '';
    public $itemsWrapperClass = '[&_.vlNoItems]:!hidden';

    protected $pendingCount;

    public function query()
    {
        return parent::query()->where('is_modal', true);
    }

    public function noItemsFound()
    {
        return null;
    }

    public function render($notification, $key)
    {
        return method_exists($notification, 'notificationModalCard')
            ? $notification->notificationModalCard($key, $this->isLastPending())
            : $this->defaultModalCard($notification, $this->isLastPending());
    }

    /** Closing the last pending row has nothing left to show, so it closes the modal instead. */
    protected function isLastPending(): bool
    {
        return ($this->pendingCount ??= $this->query()->count()) <= 1;
    }

    /** Neutral fallback card — stock Tailwind and Kompo's icon font only, like defaultBannerCard(). */
    protected function defaultModalCard($notification, bool $isLast)
    {
        $content = $notification->notificationContent();

        return !$content ? null : _Rows(
            !$notification->team_id ? null
                : _Html($notification->team->team_name)->class('text-xs text-slate-500 mb-1'),
            _Rows($content),
            $notification->is_system_message
                ? static::acknowledgeForm($notification, $isLast)
                : $this->dismissLink($notification, $isLast),
        )->class('bg-white border border-slate-200 rounded-2xl p-5 pr-10 mb-3 relative');
    }

    /** Checkbox + Close, shared with a project's own notificationModalCard() so the rule cannot drift. */
    public static function acknowledgeForm($notification, bool $isLast)
    {
        return new ModalNotificationAcknowledgeForm(null, [
            'notification_id' => $notification->id,
            'is_last' => $isLast,
        ]);
    }

    protected function dismissLink($notification, bool $isLast)
    {
        $link = _Link()->icon('icon-times')->class('absolute top-3 right-4 text-slate-400 hover:text-slate-600')
            ->post('notifications.mark-as-seen', ['id' => $notification->id]);

        // modal().close(), not closeModal(): that one only reaches this list, not the modal around it.
        return $isLast ? $link->run('({modal}) => modal().close()') : $link->browse();
    }
}
