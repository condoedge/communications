<?php

namespace Condoedge\Communications\Services\Stats;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\Stats\Dto\StatsOverviewDto;
use Condoedge\Communications\Services\Stats\Dto\TeamStatsDto;
use Condoedge\Communications\Services\Stats\Dto\TriggerStatsDto;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

/**
 * Aggregates the per-recipient send log over a team subtree.
 *
 * Every metric is a conditional aggregate over communication_sending_recipients: each
 * per-status timestamp is denormalized so "opened" is `opened_at IS NOT NULL` with zero joins.
 * The single join to communication_sendings exists only to read the denormalized `trigger`.
 */
class CommunicationStatsService implements CommunicationStatsServiceContract
{
    protected const RECIPIENTS = 'communication_sending_recipients';
    protected const SENDINGS = 'communication_sendings';

    public function __construct(
        protected TeamHierarchyInterface $hierarchy,
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
            ->selectRaw($this->openedExpr() . ' as opened_count')
            ->selectRaw('SUM(CASE WHEN ' . self::RECIPIENTS . '.sent_at IS NOT NULL AND ' . self::RECIPIENTS . '.sent_at >= ? THEN 1 ELSE 0 END) as last30d_count', [now()->subDays(30)])
            ->first();

        $sent = (int) ($row->sent_count ?? 0);
        $opened = (int) ($row->opened_count ?? 0);

        [$active, $disabled] = $this->triggerCounts($expanded);

        return new StatsOverviewDto(
            totalSent: $sent,
            failed: (int) ($row->failed_count ?? 0),
            last30d: (int) ($row->last30d_count ?? 0),
            openRate: $this->rate($opened, $sent),
            activeTriggers: $active,
            disabledTriggers: $disabled,
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
            ->selectRaw($this->openedExpr() . ' as opened_count')
            ->selectRaw('SUM(CASE WHEN ' . self::RECIPIENTS . '.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count')
            ->get()
            ->map(function ($r) {
                $sent = (int) $r->sent_count;

                return new TriggerStatsDto(
                    trigger: (string) $r->trigger,
                    sent: $sent,
                    failed: (int) $r->failed_count,
                    opened: (int) $r->opened_count,
                    clickRate: $this->rate((int) $r->clicked_count, $sent),
                );
            })
            ->values();
    }

    public function perTeam(array $teamIds): Collection
    {
        $expanded = $this->expand($teamIds);

        if (empty($expanded)) {
            return collect();
        }

        return $this->scopedRecipients($expanded)
            ->groupBy(self::RECIPIENTS . '.team_id')
            ->selectRaw(self::RECIPIENTS . '.team_id as team_id')
            ->selectRaw($this->sentExpr() . ' as sent_count')
            ->selectRaw($this->failedExpr() . ' as failed_count')
            ->selectRaw($this->openedExpr() . ' as opened_count')
            ->get()
            ->map(fn ($r) => new TeamStatsDto(
                teamId: $r->team_id !== null ? (int) $r->team_id : null,
                sent: (int) $r->sent_count,
                failed: (int) $r->failed_count,
                opened: (int) $r->opened_count,
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
     * @param int[] $expanded
     */
    protected function scopedRecipients(array $expanded): Builder
    {
        return DB::table(self::RECIPIENTS)
            ->whereNull(self::RECIPIENTS . '.deleted_at')
            ->whereIn(self::RECIPIENTS . '.team_id', $expanded);
    }

    protected function sentExpr(): string
    {
        return 'SUM(CASE WHEN ' . self::RECIPIENTS . '.sent_at IS NOT NULL THEN 1 ELSE 0 END)';
    }

    protected function openedExpr(): string
    {
        return 'SUM(CASE WHEN ' . self::RECIPIENTS . '.opened_at IS NOT NULL THEN 1 ELSE 0 END)';
    }

    protected function failedExpr(): string
    {
        return 'SUM(CASE WHEN ' . self::RECIPIENTS . '.status = ' . CommunicationSendingRecipientStatus::FAILED->value . ' THEN 1 ELSE 0 END)';
    }

    protected function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator * 100, 1);
    }

    /**
     * @param int[] $expanded
     * @return array{0:int,1:int} [activeTriggers, disabledTriggers]
     */
    protected function triggerCounts(array $expanded): array
    {
        $groups = CommunicationTemplateGroup::query()
            ->asSystemOperation()
            ->whereIn('team_id', $expanded)
            ->get(['trigger', 'disabled']);

        $disabled = $groups->where('disabled', true)->pluck('trigger')->unique();
        $active = $groups->where('disabled', '!=', true)
            ->pluck('trigger')->unique()
            ->reject(fn ($t) => $disabled->contains($t));

        return [$active->count(), $disabled->count()];
    }
}
