<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus as Status;

/**
 * What actually happened to each recipient of one channel's send.
 *
 * Recipient rows are written before delivery is attempted, so without this the persisted rows
 * record intent rather than outcome: a recipient the channel filtered out, or one whose send
 * threw, would be stamped SENT alongside the ones that genuinely received the message.
 *
 * Outcomes are keyed by the recipient's POSITION in the collection, not by any identity derived
 * from the recipient itself. Two entries can legitimately be the same person (a list unioning two
 * audiences, or two RecipientOverrides of one model with different addresses); keying by identity
 * would collapse them onto one outcome and then fan that outcome across both of their rows.
 */
class DeliveryReport
{
    /** @var array<int, array{status: Status, error: ?string}> */
    protected array $outcomes = [];

    public function sent(int $position): void
    {
        $this->record($position, Status::SENT);
    }

    public function failed(int $position, ?string $error = null): void
    {
        $this->record($position, Status::FAILED, $error);
    }

    /** Not attempted on this channel — no address, opted out, or wrong communicable type. */
    public function skipped(int $position, ?string $reason = null): void
    {
        $this->record($position, Status::SKIPPED, $reason);
    }

    public function record(int $position, Status $status, ?string $error = null): void
    {
        $this->outcomes[$position] = [
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
        ];
    }

    /** @return array<int, array{status: Status, error: ?string}> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function countOf(Status $status): int
    {
        return count(array_filter($this->outcomes, fn ($outcome) => $outcome['status'] === $status));
    }

    public function isEmpty(): bool
    {
        return $this->outcomes === [];
    }
}
