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
 *
 * A range instead of an equality re-selects the same record every night until the ledger catches
 * it. That makes the ledger load-bearing for correctness rather than for once-only, and it means
 * one email becomes ninety the first time a claims row is lost.
 *
 * SISC asserts this three times over, once per reminder. Here it is asserted once, against the
 * base class every reminder inherits.
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

    /** The reminder's own filters still apply on top of the date clause. */
    public function test_the_subject_query_filters_are_kept()
    {
        $reachable = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);
        $unreachable = Deadline::create(['due_on' => '2026-08-12', 'email' => null]);

        $due = $this->idsDueAt(ReminderOffset::before(7));

        $this->assertContains($reachable->id, $due);
        $this->assertNotContains($unreachable->id, $due);
    }

    /** The date is read off the run's frozen clock, never off a fresh now(). */
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

    /**
     * The scope's offset list is deduplicated and ordered furthest-out first.
     *
     * Asserted on STORAGE KEYS and as a LIST, because both are load-bearing in ways nothing else
     * here would notice. Dropping dedupe() lets a repeated offset through, and the sweeper claims
     * per (reminder, offset, subject) — so the duplicate is not a second email but a wasted pass
     * that reports a send it never made. Dropping array_values() leaves the array keyed by storage
     * key, which survives every count-based assertion and only surfaces once something iterates
     * with an index.
     *
     * The order is what the operator reads in the console; reversed, a timeline runs backwards and
     * nothing fails.
     */
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

    /**
     * The hour the baseline scope sweeps at, and the config key it is read from.
     *
     * The override half is the point. The shipped `kompo-communications.reminders.default_hour` is 9
     * — the same value as the lookup's literal default — so replacing the whole expression with
     * '09:00' is invisible against the package's own config, and a host that publishes the file and
     * sets a different hour silently stops being honoured. Setting the key here proves the lookup is
     * wired, not merely present.
     */
    public function test_the_baseline_scope_sweeps_at_the_configured_hour()
    {
        $this->assertEquals('09:00', collect($this->reminder->scopes($this->run))->first()->sendAt);

        Config::set('kompo-communications.reminders.default_hour', 17);

        $this->assertEquals('17:00', collect($this->reminder->scopes($this->run))->first()->sendAt);

        // The shipping shape is a STRING, not an int: Env::getOption casts only 'true', 'false',
        // 'empty' and 'null', so COMMUNICATIONS_REMINDER_HOUR=17 reaches sendAt() as '17'. Without
        // this line, deleting the numeric-string arm of sendAt()'s guard leaves the whole suite
        // green and throws on every sweep for every env-configured host.
        Config::set('kompo-communications.reminders.default_hour', '17');

        $this->assertEquals('17:00', collect($this->reminder->scopes($this->run))->first()->sendAt);

        // A key that is PRESENT and null is not a missing key: config()'s second argument never
        // fires, so `'default_hour' => env('COMMUNICATIONS_REMINDER_HOUR')` with the env unset
        // reaches sendAt() as null. It must land on nine, not on a cast to midnight.
        Config::set('kompo-communications.reminders.default_hour', null);

        $this->assertEquals('09:00', collect($this->reminder->scopes($this->run))->first()->sendAt);
    }

    /**
     * A non-numeric configured hour is rejected loudly here, at the only place it can be.
     *
     * ReminderScope's HH:MM guard catches the out-of-range half of this ('25:00' throws) and
     * cannot catch the other half: 'noon', true and null all cast to 0 and produce a perfectly
     * well-formed '00:00' that passes the regex. Left to the cast, a config typo moves every scope
     * in the install to midnight with no exception, no log line and a green exit code — a
     * configuration error failing open and silent.
     */
    public function test_a_non_numeric_configured_hour_is_rejected_rather_than_cast()
    {
        Config::set('kompo-communications.reminders.default_hour', 'noon');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'noon'");

        $this->reminder->scopes($this->run);
    }

    /**
     * The scope handed to subjectQuery() is the one the sweeper selected under.
     *
     * Without this, dueAt() could fabricate a scope — `new ReminderScope(null, [], '09:00')` — and
     * every other test here still passes, because the package's only subjectQuery() implementation
     * ignores its argument. That parameter is the §6 per-team seam: it is what a host's query reads
     * to narrow a sweep to the team whose schedule is firing, so it needs one executable example.
     */
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
