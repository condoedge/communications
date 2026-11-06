<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderRun;
use Condoedge\Communications\Reminders\ReminderScope;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * The exact-day clause, which is the single most consequential line in the layer.
 */
class DateAnchoredSelectionTest extends TestCase
{
    protected DeadlineReminder $reminder;
    protected ReminderRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reminder = app(DeadlineReminder::class);
        $this->run = new ReminderRun(CarbonImmutable::parse('2026-08-05'), 9);
    }

    protected function idsDueAt(ReminderOffset $offset): array
    {
        $scope = collect($this->reminder->scopes($this->run))->first();

        return collect($this->reminder->dueAt($scope, $offset, $this->run))->pluck('id')->all();
    }

    public function test_a_subject_is_picked_up_on_the_exact_day_and_not_around_it()
    {
        $onTheDay = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);
        $dayBefore = Deadline::create(['due_on' => '2026-08-11', 'email' => 'a@b.test']);
        $dayAfter = Deadline::create(['due_on' => '2026-08-13', 'email' => 'a@b.test']);

        $due = $this->idsDueAt(ReminderOffset::before(7));

        $this->assertContains($onTheDay->id, $due);
        $this->assertNotContains($dayBefore->id, $due, 'a range instead of an exact day would re-send every night');
        $this->assertNotContains($dayAfter->id, $due);
    }

    public function test_an_after_offset_selects_past_the_anchor()
    {
        $overdue = Deadline::create(['due_on' => '2026-07-29', 'email' => 'a@b.test']);

        $this->assertContains($overdue->id, $this->idsDueAt(ReminderOffset::after(7)));
        $this->assertNotContains($overdue->id, $this->idsDueAt(ReminderOffset::before(7)));
    }

    public function test_on_the_day_selects_the_anchor_itself()
    {
        $today = Deadline::create(['due_on' => '2026-08-05', 'email' => 'a@b.test']);

        $this->assertContains($today->id, $this->idsDueAt(ReminderOffset::onTheDay()));
    }

    public function test_the_subject_query_filters_are_kept()
    {
        $reachable = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);
        $unreachable = Deadline::create(['due_on' => '2026-08-12', 'email' => null]);

        $due = $this->idsDueAt(ReminderOffset::before(7));

        $this->assertContains($reachable->id, $due);
        $this->assertNotContains($unreachable->id, $due);
    }

    public function test_selection_uses_the_runs_clock_not_the_wall_clock()
    {
        CarbonImmutable::setTestNow('2030-01-01 00:00:00');

        $due = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->assertContains($due->id, $this->idsDueAt(ReminderOffset::before(7)));

        CarbonImmutable::setTestNow();
    }

    public function test_a_reminder_exposes_one_baseline_scope_today()
    {
        $scopes = collect($this->reminder->scopes($this->run));

        $this->assertCount(1, $scopes);
        $this->assertNull($scopes->first()->teamId);
        $this->assertEquals('baseline', $scopes->first()->label());
    }

    public function test_the_baseline_scope_dedupes_its_offsets_and_orders_them_furthest_out_first()
    {
        $reminder = new class extends DeadlineReminder {
            protected function offsets(): array
            {
                return [
                    ReminderOffset::after(7),
                    ReminderOffset::before(7),
                    ReminderOffset::onTheDay(),
                    ReminderOffset::before(30),
                    ReminderOffset::before(7),
                ];
            }
        };

        $offsets = collect($reminder->scopes($this->run))->first()->offsets;

        $this->assertSame(
            ['b30', 'b7', 'd0', 'a7'],
            array_map(fn ($offset) => $offset->storageKey(), $offsets),
        );
    }

    public function test_the_baseline_scope_sweeps_at_the_configured_hour()
    {
        $this->assertEquals('09:00', collect($this->reminder->scopes($this->run))->first()->sendAt);

        Config::set('kompo-communications.reminders.default_hour', 17);

        $this->assertEquals('17:00', collect($this->reminder->scopes($this->run))->first()->sendAt);

        Config::set('kompo-communications.reminders.default_hour', '17');

        $this->assertEquals('17:00', collect($this->reminder->scopes($this->run))->first()->sendAt);

        Config::set('kompo-communications.reminders.default_hour', null);

        $this->assertEquals('09:00', collect($this->reminder->scopes($this->run))->first()->sendAt);
    }

    public function test_a_non_numeric_configured_hour_is_rejected_rather_than_cast()
    {
        Config::set('kompo-communications.reminders.default_hour', 'noon');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'noon'");

        $this->reminder->scopes($this->run);
    }

    public function test_the_scope_is_handed_to_the_subject_query()
    {
        $reminder = new class extends DeadlineReminder {
            protected function subjectQuery(ReminderScope $scope): Builder
            {
                $query = Deadline::query()->whereNotNull('email');

                return $scope->teamId !== null
                    ? $query->where('team_id', $scope->teamId)
                    : $query;
            }
        };

        $mine = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test', 'team_id' => 1]);
        $theirs = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test', 'team_id' => 2]);

        $scope = new ReminderScope(1, [ReminderOffset::before(7)], '09:00');

        $due = collect($reminder->dueAt($scope, ReminderOffset::before(7), $this->run))->pluck('id')->all();

        $this->assertContains($mine->id, $due);
        $this->assertNotContains($theirs->id, $due, 'a fabricated scope would leak every team into every sweep');
    }
}
