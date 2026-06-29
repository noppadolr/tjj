<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CommissionRate;
use App\Models\Trade;
use App\Models\TradeCommission;
use App\Models\TradeExit;

class TradeCostCalculator
{
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
        $underlying = preg_replace('/[FGHJKMNQUVXZ]\d{2}$/', '', $contract) ?: $contract;
        $contracts = collect([$contract, $underlying])->filter()->unique()->values();

        $query = CommissionRate::query()
            ->where('is_active', true)
            ->whereIn('contract', $contracts)
            ->orderByRaw('case when contract = ? then 0 else 1 end', [$contract]);

        return (clone $query)
            ->whereDate('effective_date', '<=', $effectiveDate)
            ->latest('effective_date')
            ->first()
            ?? $query->oldest('effective_date')->first();
    }

    private function tradeCommissionFor(TradeExit $exit): ?TradeCommission
    {
        $tradeCommission = $exit->relationLoaded('tradeCommission')
            ? $exit->getRelation('tradeCommission')
            : $exit->tradeCommission()->first();

        return $tradeCommission instanceof TradeCommission ? $tradeCommission : null;
    }
}
