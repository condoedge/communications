<?php

namespace Condoedge\Communications\Components;

use Kompo\Auth\Monitoring\NotificationsList as PackageList;

/**
 * The regular notifications list: every live notification EXCEPT the ones flagged as banners.
 *
 * Banners are drawn full width by BannersList, so leaving them here too would show the same
 * notification twice. Only the partition is stated — which rows are live stays the parent's
 * decision, so the two lists cannot drift apart.
 */
class NotificationsList extends PackageList
{
    public function query()
    {
        return parent::query()->where('is_banner_type', false);
    }
}
