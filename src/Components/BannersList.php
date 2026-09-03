<?php

namespace Condoedge\Communications\Components;

use Kompo\Auth\Monitoring\NotificationsList as PackageList;

/**
 * The banner half of the notifications split: rows flagged `is_banner_type` at write time
 * (a trigger returns `is_banner_type => true` from getParams()), drawn full width above the
 * page instead of as cards in a column.
 *
 * Rendering is delegated to the notification model when it defines `notificationBannerCard()`,
 * so each project styles its own banners; defaultBannerCard() is the package fallback.
 */
class BannersList extends PackageList
{
    /** Banners are few and full width: no scroll box, no dead space, no pagination links. */
    public $hasPagination = false;
    public $itemsWrapperStyle = '';

    /**
     * `.vlNoItems` carries py-4 px-6, and Kompo renders it whenever noItemsFound() is null — so
     * without this the page would gain 2rem of blank space above the fold on the common case of
     * having no banners at all.
     */
    public $itemsWrapperClass = '[&_.vlNoItems]:!hidden';

    public function query()
    {
        return parent::query()->where('is_banner_type', true);
    }

    public function noItemsFound()
    {
        return null;
    }

    public function render($notification, $key)
    {
        // method_exists on the instance: the class comes from the notification-model binding, so
        // each project's own model answers. This package never needs to name it.
        return method_exists($notification, 'notificationBannerCard')
            ? $notification->notificationBannerCard($key)
            : $this->defaultBannerCard($notification);
    }

    /**
     * Neutral fallback banner, for a project that has not defined its own.
     *
     * Wraps notificationContent() rather than rebuilding it: that call already carries the
     * message, the resolved button handlers and the reminder dropdown. Stock Tailwind colors and
     * Kompo's own icon font only — a host app is not assumed to have a brand palette or the sax
     * icon set (_Sax reads from public/icons and would fatal where it is not published).
     */
    protected function defaultBannerCard($notification)
    {
        $content = $notification->notificationContent();

        return !$content ? null : _Flex(
            _Div()->class('w-1.5 self-stretch rounded-full bg-amber-400 shrink-0'),
            _Rows(
                !$notification->team_id ? null
                    : _Html($notification->team->team_name)->class('text-xs text-slate-500 mb-1'),
                _Rows($content),
            )->class('flex-1 min-w-0'),
            $this->dismissLink($notification),
        )->class('items-stretch gap-4 bg-white border border-amber-300 rounded-2xl p-5 pr-10 mb-3 shadow-sm relative');
    }

    protected function dismissLink($notification)
    {
        return _Link()->icon('icon-times')
            ->class('absolute top-3 right-4 text-slate-400 hover:text-slate-600')
            ->post('notifications.mark-as-seen', ['id' => $notification->id])
            ->browse();
    }
}
