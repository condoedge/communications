<?php

namespace Condoedge\Communications\Tests\Feature\Stats;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Deliveries, opens, clicks and bounces are counted on their timestamps.
 */
class CommunicationStatsEngagementTest extends TestCase
{
    protected int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = DB::table('teams')->insertGetId([
            'team_name' => 'Stats probe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->subject = json_encode(['en' => 'Stats probe']);
        $template->content = json_encode(['en' => '<p>Stats probe</p>']);
        $template->save();

        $rows = CommunicationSending::createOneForCommunicationTemplate(
            $template,
            collect([
                new StatsProbeRecipient('opened@example.test'),
                new StatsProbeRecipient('clicked@example.test'),
                new StatsProbeRecipient('bounced@example.test'),
            ]),
            ['team_id' => $this->teamId, 'communication_teams' => [$this->teamId]],
        )->recipients()->orderBy('position')->pluck('id');

        $now = now();

        $this->stamp($rows[0], ['delivered_at' => $now, 'opened_at' => $now]);
        $this->stamp($rows[1], ['opened_at' => $now, 'clicked_at' => $now]);
        $this->stamp($rows[2], ['bounced_at' => $now]);
    }

    protected function stamp(int $rowId, array $timestamps): void
    {
        DB::table('communication_sending_recipients')->where('id', $rowId)->update($timestamps);
    }

    protected function stats(): CommunicationStatsServiceContract
    {
        return app(CommunicationStatsServiceContract::class);
    }

    protected function assertEngagement(object $dto): void
    {
        $this->assertSame(1, $dto->delivered);
        $this->assertSame(2, $dto->opened);
        $this->assertSame(1, $dto->clicked);
        $this->assertSame(1, $dto->bounced);
    }

    public function test_the_overview_counts_engagement(): void
    {
        $this->assertEngagement($this->stats()->overview([$this->teamId]));
    }

    public function test_the_per_trigger_rows_count_engagement(): void
    {
        $rows = $this->stats()->perTrigger([$this->teamId]);

        $this->assertCount(1, $rows);
        $this->assertEngagement($rows->first());
    }

    public function test_the_per_team_rows_count_engagement(): void
    {
        $rows = $this->stats()->perTeam([$this->teamId]);

        $this->assertCount(1, $rows);
        $this->assertSame($this->teamId, $rows->first()->teamId);
        $this->assertEngagement($rows->first());
    }
}

class StatsProbeRecipient implements EmailCommunicable
{
    public function __construct(private string $email)
    {
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getContextKey()
    {
        return 'stats_probe';
    }

    public function label()
    {
        return 'Stats probe';
    }

    public function scopeValidForCommunication($query)
    {
        throw new \LogicException('not queryable');
    }

    public function scopeSearch($query, $search)
    {
        throw new \LogicException('not queryable');
    }
}
