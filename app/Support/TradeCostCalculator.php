<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CommissionRate;
use App\Models\Trade;
use App\Models\TradeCommission;
use App\Models\TradeExit;
use Illuminate\Support\Carbon;

class TradeCostCalculator
{
    /**
     * Per-request memoization of commission rate lookups, keyed by "contract|date".
     * Safe because this class is bound as a singleton (see AppServiceProvider), so the
     * cache lives only for the duration of one request/render and is rebuilt fresh each time.
     *
     * @var array<string, CommissionRate|null>
     */
    private array $commissionRateCache = [];

    public function openingCommissionFor(Trade $trade): float
    {
        $rate = $this->commissionRateForTrade($trade, $trade->trade_date);

        if (! $rate) {
            return 0.0;
        }

        return round((int) $trade->total_contracts * (float) $rate->commission_per_contract, 2);
    }

    public function openingVatFor(Trade $trade): float
    {
        $rate = $this->commissionRateForTrade($trade, $trade->trade_date);

        if (! $rate) {
            return 0.0;
        }

        return round($this->openingCommissionFor($trade) * (float) $rate->vat_percent / 100, 2);
    }

    public function openingTotalCostFor(Trade $trade): float
    {
        return round($this->openingCommissionFor($trade) + $this->openingVatFor($trade), 2);
    }

    public function closingCommissionFor(TradeExit $exit, ?Trade $trade = null): float
    {
        $rate = $this->commissionRateFor($exit, $trade);

        if (! $rate) {
            return round($this->commissionFor($exit, $trade) / 2, 2);
        }

        return round((int) $exit->exit_contracts * (float) $rate->commission_per_contract, 2);
    }

    public function closingVatFor(TradeExit $exit, ?Trade $trade = null): float
    {
        $rate = $this->commissionRateFor($exit, $trade);

        if (! $rate) {
            return round($this->vatFor($exit, $trade) / 2, 2);
        }

        return round($this->closingCommissionFor($exit, $trade) * (float) $rate->vat_percent / 100, 2);
    }

    public function closingTotalCostFor(TradeExit $exit, ?Trade $trade = null): float
    {
        return round($this->closingCommissionFor($exit, $trade) + $this->closingVatFor($exit, $trade), 2);
    }

    public function commissionFor(TradeExit $exit, ?Trade $trade = null): float
    {
        $rate = $this->commissionRateFor($exit, $trade);

        if ($rate) {
            return round((int) $exit->exit_contracts * (float) $rate->commission_per_contract * 2, 2);
        }

        $tradeCommission = $this->tradeCommissionFor($exit);

        if ($tradeCommission) {
            return (float) $tradeCommission->commission;
        }

        return (float) ($exit->commission ?? 0);
    }

    public function vatFor(TradeExit $exit, ?Trade $trade = null): float
    {
        $rate = $this->commissionRateFor($exit, $trade);

        if ($rate) {
            return round($this->commissionFor($exit, $trade) * (float) $rate->vat_percent / 100, 2);
        }

        $tradeCommission = $this->tradeCommissionFor($exit);

        if ($tradeCommission) {
            return (float) $tradeCommission->vat;
        }

        return (float) ($exit->vat ?? 0);
    }

    public function totalCostFor(TradeExit $exit, ?Trade $trade = null): float
    {
        $rate = $this->commissionRateFor($exit, $trade);

        if ($rate) {
            return round($this->commissionFor($exit, $trade) + $this->vatFor($exit, $trade), 2);
        }

        $tradeCommission = $this->tradeCommissionFor($exit);
        $stored = (float) ($tradeCommission ? $tradeCommission->total_cost : ($exit->total_cost ?? 0));

        if ($stored > 0) {
            return $stored;
        }

        return round($this->commissionFor($exit, $trade) + $this->vatFor($exit, $trade), 2);
    }

    public function netFor(TradeExit $exit, ?Trade $trade = null): float
    {
        return round((float) $exit->gross_profit - $this->totalCostFor($exit, $trade), 2);
    }

    public function commissionRateFor(TradeExit $exit, ?Trade $trade = null): ?CommissionRate
    {
        $trade ??= $exit->trade;

        return $this->commissionRateForTrade($trade, $exit->exit_date);
    }

    private function commissionRateForTrade(?Trade $trade, mixed $effectiveDate): ?CommissionRate
    {
        $contract = strtoupper((string) $trade?->contract);
        $date = Carbon::parse($effectiveDate)->format('Y-m-d');
        $cacheKey = $contract.'|'.$date;

        if (array_key_exists($cacheKey, $this->commissionRateCache)) {
            return $this->commissionRateCache[$cacheKey];
        }

        $underlying = preg_replace('/[FGHJKMNQUVXZ]\d{2}$/', '', $contract) ?: $contract;
        $contracts = collect([$contract, $underlying])->filter()->unique()->values();

        $query = CommissionRate::query()
            ->where('is_active', true)
            ->whereIn('contract', $contracts)
            ->orderByRaw('case when contract = ? then 0 else 1 end', [$contract]);

        $rate = (clone $query)
            ->whereDate('effective_date', '<=', $date)
            ->latest('effective_date')
            ->first()
            ?? $query->oldest('effective_date')->first();

        return $this->commissionRateCache[$cacheKey] = $rate;
    }

    private function tradeCommissionFor(TradeExit $exit): ?TradeCommission
    {
        $tradeCommission = $exit->relationLoaded('tradeCommission')
            ? $exit->getRelation('tradeCommission')
            : $exit->tradeCommission()->first();

        return $tradeCommission instanceof TradeCommission ? $tradeCommission : null;
    }
}
