<?php

namespace Condoedge\Communications\Services\Stats;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Services\Stats\Dto\StatsOverviewDto;
use Condoedge\Communications\Services\Stats\Dto\TeamStatsDto;
use Condoedge\Communications\Services\Stats\Dto\TriggerStatsDto;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateSource;
use Condoedge\Communications\Triggers\ManualTrigger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

/**
 * Aggregates the per-recipient send log over a team subtree.
 *
 * Every metric is a conditional aggregate over communication_sending_recipients: each per-status
 * timestamp is denormalized so "opened" is `opened_at IS NOT NULL`. A recipient's teams live in the
 * communication_sending_recipient_teams pivot, so a send relevant to several teams is counted once
 * for totals (scoped via EXISTS) but appears under each team in the per-team breakdown (a pivot join).
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
            ->selectRaw($this->openedExpr() . ' as opened_count')
            ->selectRaw('SUM(CASE WHEN ' . self::RECIPIENTS . '.sent_at IS NOT NULL AND ' . self::RECIPIENTS . '.sent_at >= ? THEN 1 ELSE 0 END) as last30d_count', [now()->subDays(30)])
            ->first();

        $sent = (int) ($row->sent_count ?? 0);
        $opened = (int) ($row->opened_count ?? 0);

        // Anchor teams (not the expanded subtree): trigger state is resolved per viewing team.
        [$active, $disabled] = $this->triggerCounts($teamIds);

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
            ->selectRaw($this->openedExpr() . ' as opened_count')
            ->get()
            ->map(fn ($r) => new TeamStatsDto(
                teamId: (int) $r->team_id,
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
            ->reject(fn ($trigger) => $trigger === ManualTrigger::class)
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
