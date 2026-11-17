<?php

namespace Condoedge\Communications\Services\Stats;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Services\Stats\Dto\StatsOverviewDto;
use Condoedge\Communications\Services\Stats\Dto\TeamStatsDto;
use Condoedge\Communications\Services\Stats\Dto\TriggerStatsDto;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

/**
 * Aggregates the per-recipient send log over a team subtree. A send recorded against several teams
 * counts once in totals and under each team in the per-team breakdown. Deliveries, opens, clicks and
 * bounces are counted on their own timestamps, not on the status: a clicked row was also opened.
 */
class CommunicationStatsService implements CommunicationStatsServiceContract
{
    protected const RECIPIENTS = 'communication_sending_recipients';
    protected const SENDINGS = 'communication_sendings';
    protected const PIVOT = 'communication_sending_recipient_teams';

    public function __construct(
        protected TeamHierarchyInterface $hierarchy,
        protected EffectiveTemplateResolverContract $resolver,
    ) {
    }

    public function overview(array $teamIds): StatsOverviewDto
    {
        $expanded = $this->expand($teamIds);

        if (empty($expanded)) {
            return new StatsOverviewDto();
        }

        $row = $this->scopedRecipients($expanded)
            ->selectRaw($this->sentExpr() . ' as sent_count')
            ->selectRaw($this->failedExpr() . ' as failed_count')
            ->selectRaw($this->engagementSelect())
            ->selectRaw('SUM(CASE WHEN ' . self::RECIPIENTS . '.sent_at IS NOT NULL AND ' . self::RECIPIENTS . '.sent_at >= ? THEN 1 ELSE 0 END) as last30d_count', [now()->subDays(30)])
            ->first();

        // Anchor teams (not the expanded subtree): trigger state is resolved per viewing team.
        [$active, $disabled] = $this->triggerCounts($teamIds);

        return new StatsOverviewDto(
            totalSent: (int) ($row->sent_count ?? 0),
            failed: (int) ($row->failed_count ?? 0),
            last30d: (int) ($row->last30d_count ?? 0),
            activeTriggers: $active,
            disabledTriggers: $disabled,
            delivered: (int) ($row->delivered_count ?? 0),
            opened: (int) ($row->opened_count ?? 0),
            clicked: (int) ($row->clicked_count ?? 0),
            bounced: (int) ($row->bounced_count ?? 0),
        );
    }

    public function perTrigger(array $teamIds): Collection
    {
        $expanded = $this->expand($teamIds);

        if (empty($expanded)) {
            return collect();
        }

        return $this->scopedRecipients($expanded)
            ->join(self::SENDINGS, self::SENDINGS . '.id', '=', self::RECIPIENTS . '.communication_sending_id')
            ->groupBy(self::SENDINGS . '.trigger')
            ->selectRaw('`' . self::SENDINGS . '`.`trigger` as `trigger`')
            ->selectRaw($this->sentExpr() . ' as sent_count')
            ->selectRaw($this->failedExpr() . ' as failed_count')
            ->selectRaw($this->engagementSelect())
            ->get()
            ->map(fn ($r) => new TriggerStatsDto(
                trigger: (string) $r->trigger,
                sent: (int) $r->sent_count,
                failed: (int) $r->failed_count,
                delivered: (int) $r->delivered_count,
                opened: (int) $r->opened_count,
                clicked: (int) $r->clicked_count,
                bounced: (int) $r->bounced_count,
            ))
            ->values();
    }

    public function perTeam(array $teamIds): Collection
    {
        $expanded = $this->expand($teamIds);

        if (empty($expanded)) {
            return collect();
        }

        // Join the team pivot: a recipient recorded against several in-scope teams is counted under
        // each (these breakdown rows can sum past the deduped total — that is intentional).
        return DB::table(self::RECIPIENTS)
            ->whereNull(self::RECIPIENTS . '.deleted_at')
            ->join(self::PIVOT, self::PIVOT . '.communication_sending_recipient_id', '=', self::RECIPIENTS . '.id')
            ->whereIn(self::PIVOT . '.team_id', $expanded)
            ->groupBy(self::PIVOT . '.team_id')
            ->selectRaw(self::PIVOT . '.team_id as team_id')
            ->selectRaw($this->sentExpr() . ' as sent_count')
            ->selectRaw($this->failedExpr() . ' as failed_count')
            ->selectRaw($this->engagementSelect())
            ->get()
            ->map(fn ($r) => new TeamStatsDto(
                teamId: (int) $r->team_id,
                sent: (int) $r->sent_count,
                failed: (int) $r->failed_count,
                delivered: (int) $r->delivered_count,
                opened: (int) $r->opened_count,
                clicked: (int) $r->clicked_count,
                bounced: (int) $r->bounced_count,
            ))
            ->values();
    }

    /* ============================== Internals ============================== */

    /**
     * @param int[] $teamIds
     * @return int[]
     */
    protected function expand(array $teamIds): array
    {
        $all = collect();

        foreach (array_map('intval', $teamIds) as $tid) {
            $all = $all->merge($this->hierarchy->getDescendantTeamIds($tid));
        }

        return $all->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Recipients with at least one team in scope. EXISTS against the pivot (not a join) so a recipient
     * recorded against several in-scope teams is still counted once for totals.
     *
     * @param int[] $expanded
     */
    protected function scopedRecipients(array $expanded): Builder
    {
        return DB::table(self::RECIPIENTS)
            ->whereNull(self::RECIPIENTS . '.deleted_at')
            ->whereExists(fn ($query) => $query->from(self::PIVOT)
                ->whereColumn(self::PIVOT . '.communication_sending_recipient_id', self::RECIPIENTS . '.id')
                ->whereIn(self::PIVOT . '.team_id', $expanded));
    }

    protected function sentExpr(): string
    {
        return 'SUM(CASE WHEN ' . self::RECIPIENTS . '.sent_at IS NOT NULL THEN 1 ELSE 0 END)';
    }

    protected function failedExpr(): string
    {
        // Interpolated, not bound: the only variable part is an int-backed enum case, never input.
        return 'SUM(CASE WHEN ' . self::RECIPIENTS . '.status = ' . (int) CommunicationSendingRecipientStatus::FAILED->value . ' THEN 1 ELSE 0 END)';
    }

    protected function engagementSelect(): string
    {
        return collect(['delivered', 'opened', 'clicked', 'bounced'])
            ->map(fn ($metric) => 'SUM(CASE WHEN ' . self::RECIPIENTS . '.' . $metric . '_at IS NOT NULL THEN 1 ELSE 0 END) as ' . $metric . '_count')
            ->implode(', ');
    }

    /**
     * Active / disabled trigger counts as the resolver sees them for the anchor teams — the same
     * decision the Templates tab and the runtime send path make. Resolving (rather than scanning raw
     * group rows) means a trigger that is configured only on the system baseline still counts as
     * active, and a closer DISABLED row correctly wins over a farther enabled one.
     *
     * Across multiple anchor teams a trigger is active if it resolves sendable for any of them, and
     * disabled if any anchor resolves it DISABLED while none resolve it sendable.
     *
     * @param int[] $teamIds anchor teams (not the expanded subtree)
     * @return array{0:int,1:int} [activeTriggers, disabledTriggers]
     */
    protected function triggerCounts(array $teamIds): array
    {
        $triggers = collect(config('kompo-communications.triggers', []))
            ->reject(fn ($trigger) => method_exists($trigger, 'getSpecificCommunicationsIds'))
            ->unique()
            ->values();

        $active = 0;
        $disabled = 0;

        foreach ($triggers as $trigger) {
            $resolutions = collect($teamIds)
                ->map(fn ($teamId) => $this->resolver->resolve($trigger, (int) $teamId));

            if ($resolutions->contains(fn ($resolution) => $resolution->isSendable())) {
                $active++;
            } elseif ($resolutions->contains(fn ($resolution) => $resolution->source === EffectiveTemplateSource::DISABLED)) {
                $disabled++;
            }
        }

        return [$active, $disabled];
    }
}
