<?php

namespace Condoedge\Communications\Tests\Stubs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\Contracts\HasReminderAnchor;
use Illuminate\Database\Eloquent\Model;

/**
 * The package's own reminder subject. One date, one address, nothing else.
 */
class Deadline extends Model implements HasReminderAnchor
{
    protected $table = 'test_deadlines';

    protected $guarded = [];

    public $timestamps = false;

    public function reminderAnchorDate(string $reminderKey): ?CarbonInterface
    {
        return $this->due_on ? CarbonImmutable::parse($this->due_on) : null;
    }
}
