<?php

namespace Condoedge\Communications\Services\Engagement;

use Carbon\CarbonInterface;
use Condoedge\Communications\Models\CommunicationSendingRecipient;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus as Status;

/**
 * Writes what a mail provider reports about one recipient of a sending. The host calls it from its
 * own webhook: the package knows no provider.
 */
class EngagementRecorder
{
    protected const TIMESTAMPS = [
        Status::DELIVERED->value => 'delivered_at',
        Status::OPENED->value => 'opened_at',
        Status::CLICKED->value => 'clicked_at',
        Status::BOUNCED->value => 'bounced_at',
    ];

    // A status only moves forward: a delivery report arriving after a click changes nothing.
    protected const RANK = [
        Status::PENDING->value => 0,
        Status::SENT->value => 1,
        Status::DELIVERED->value => 2,
        Status::BOUNCED->value => 2,
        Status::OPENED->value => 3,
        Status::CLICKED->value => 4,
    ];

    public function record(
        int $recipientRowId,
        Status $status,
        ?CarbonInterface $at = null,
        ?string $providerEvent = null,
        ?string $providerMessageId = null,
    ): bool {
        $row = CommunicationSendingRecipient::find($recipientRowId);

        if (!$row || !isset(static::RANK[$status->value])) {
            return false;
        }

        $column = static::TIMESTAMPS[$status->value] ?? null;

        if ($column && !$row->{$column}) {
            $row->{$column} = $at ?? now();
        }

        if (static::RANK[$status->value] > (static::RANK[$row->status?->value ?? 0] ?? 0)) {
            $row->status = $status;
        }

        $row->provider_event = $providerEvent ?? $row->provider_event;
        $row->provider_message_id = $providerMessageId ?? $row->provider_message_id;
        $row->save();

        return true;
    }
}
