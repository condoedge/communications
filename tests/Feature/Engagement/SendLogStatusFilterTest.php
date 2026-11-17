<?php

namespace Condoedge\Communications\Tests\Feature\Engagement;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus as Status;
use Condoedge\Communications\Tests\TestCase;

/**
 * The statuses the send log offers as a filter.
 */
class SendLogStatusFilterTest extends TestCase
{
    public function test_the_engagement_statuses_are_not_offered_by_default(): void
    {
        $this->assertSame(
            [Status::PENDING->value, Status::SENT->value, Status::FAILED->value, Status::SKIPPED->value],
            array_keys(Status::filterOptions()),
        );
    }

    public function test_a_host_that_records_engagement_can_filter_on_it(): void
    {
        config(['kompo-communications.engagement-stats' => true]);

        $options = Status::filterOptions();

        $this->assertSame(
            [
                Status::PENDING->value, Status::SENT->value, Status::FAILED->value, Status::SKIPPED->value,
                Status::DELIVERED->value, Status::OPENED->value, Status::CLICKED->value, Status::BOUNCED->value,
            ],
            array_keys($options),
        );

        $this->assertSame(Status::OPENED->label(), $options[Status::OPENED->value]);
    }
}
