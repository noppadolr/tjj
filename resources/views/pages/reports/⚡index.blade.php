<?php

use App\Livewire\BaseIndexComponent;
use App\Models\TradeExit;
use App\Support\TradeCostCalculator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;

new class extends BaseIndexComponent {
    public int $perPage = 15;
    public string $from = '';
    public string $to = '';
    public string $contract = '';

    public function exportUrl(string $format): string
    {
        return route('reports.export', array_filter([
            'format' => $format,
            'from' => $this->from ?: null,
            'to' => $this->to ?: null,
            'contract' => $this->contract ?: null,
        ]));
    }

    #[Computed]
    public function exits()
    {
        return $this->getRowsQuery()->paginate($this->perPage);
    }

    protected function getRowsQuery(): Builder
    {
        return TradeExit::query()
            ->with(['trade', 'tradeCommission'])
            ->when($this->from, fn (Builder $query) => $query->whereDate('exit_date', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('exit_date', '<=', $this->to))
            ->when($this->contract, fn (Builder $query) => $query->whereHas('trade', fn (Builder $tradeQuery) => $tradeQuery->where('contract', 'like', '%'.$this->contract.'%')))
            ->latest('exit_date');
    }

    #[Computed]
    public function totals(): array
    {
        $exits = $this->getRowsQuery()->get();

        return [
            'net' => (float) $exits->sum(fn ($exit) => $this->netFor($exit)),
            'gross' => (float) $exits->sum('gross_profit'),
            'cost' => (float) $exits->sum(fn ($exit) => $this->totalCostFor($exit)),
        ];
    }

    #[Computed]
    public function contractSummary(): \Illuminate\Support\Collection
    {
        $exits = $this->getRowsQuery()->get();

        return $exits->groupBy(fn (TradeExit $exit) => $exit->trade->contract)
            ->map(function ($group, $contract) {
                $wins = $group->filter(fn (TradeExit $exit) => $this->netFor($exit) > 0)->count();

                return [
                    'contract' => $contract,
                    'trades' => $group->count(),
                    'win_rate' => $group->count() ? $wins / $group->count() * 100 : 0,
                    'gross' => (float) $group->sum('gross_profit'),
                    'cost' => (float) $group->sum(fn (TradeExit $exit) => $this->totalCostFor($exit)),
                    'net' => (float) $group->sum(fn (TradeExit $exit) => $this->netFor($exit)),
                ];
            })
            ->sortByDesc('net')
            ->values();
    }

    #[Computed]
    public function monthlySummary(): \Illuminate\Support\Collection
    {
        $exits = $this->getRowsQuery()->get();

        return $exits->groupBy(fn (TradeExit $exit) => $exit->exit_date->format('Y-m'))
            ->map(function ($group, $period) {
                return [
                    'period' => $period,
                    'label' => thai_month_short($group->first()->exit_date->month).' '.($group->first()->exit_date->year + 543),
                    'trades' => $group->count(),
                    'gross' => (float) $group->sum('gross_profit'),
                    'cost' => (float) $group->sum(fn (TradeExit $exit) => $this->totalCostFor($exit)),
                    'net' => (float) $group->sum(fn (TradeExit $exit) => $this->netFor($exit)),
                ];
            })
            ->sortByDesc('period')
            ->values();
    }

    #[Computed]
    public function yearlySummary(): \Illuminate\Support\Collection
    {
        $exits = $this->getRowsQuery()->get();

        return $exits->groupBy(fn (TradeExit $exit) => $exit->exit_date->year)
            ->map(function ($group, $year) {
                return [
                    'year' => $year + 543,
                    'trades' => $group->count(),
                    'gross' => (float) $group->sum('gross_profit'),
                    'cost' => (float) $group->sum(fn (TradeExit $exit) => $this->totalCostFor($exit)),
                    'net' => (float) $group->sum(fn (TradeExit $exit) => $this->netFor($exit)),
                ];
            })
            ->sortByDesc('year')
            ->values();
    }

    public function commissionFor(TradeExit $exit): float
    {
        return $this->costCalculator()->commissionFor($exit);
    }

    public function vatFor(TradeExit $exit): float
    {
        return $this->costCalculator()->vatFor($exit);
    }

    public function totalCostFor(TradeExit $exit): float
    {
        return $this->costCalculator()->totalCostFor($exit);
    }

    public function netFor(TradeExit $exit): float
    {
        return $this->costCalculator()->netFor($exit);
    }

    private function costCalculator(): TradeCostCalculator
    {
        return app(TradeCostCalculator::class);
    }
};
?>
<div class="space-y-6"><div class="flex flex-wrap items-start justify-between gap-4"><div><flux:heading size="xl">Reports</flux:heading><flux:text>Review realized exits and trading costs.</flux:text></div><div class="flex gap-2"><flux:button icon="document-arrow-down" :href="$this->exportUrl('xlsx')">Export Excel</flux:button><flux:button icon="document-arrow-down" :href="$this->exportUrl('pdf')">Export PDF</flux:button></div></div>
    <flux:card class="space-y-4"><div class="grid gap-4 md:grid-cols-3">@include('partials.thai-date-picker', ['field' => 'from', 'label' => 'From (พ.ศ.)', 'clearable' => true, 'live' => true, 'placeholder' => 'ทุกวันที่'])@include('partials.thai-date-picker', ['field' => 'to', 'label' => 'To (พ.ศ.)', 'clearable' => true, 'live' => true, 'placeholder' => 'ทุกวันที่'])<flux:input wire:model.live.debounce.300ms="contract" label="Contract" placeholder="ค้นหาด้วย Symbol สัญญา เช่น S50"/></div></flux:card>
    <div class="grid gap-4 md:grid-cols-3"><flux:card><flux:text>Gross P/L</flux:text><flux:heading size="xl">{{ number_format($this->totals['gross'],2) }}</flux:heading></flux:card><flux:card><flux:text>Total Cost</flux:text><flux:heading size="xl">{{ number_format($this->totals['cost'],2) }}</flux:heading></flux:card><flux:card><flux:text>Net P/L</flux:text><flux:heading size="xl">{{ number_format($this->totals['net'],2) }}</flux:heading></flux:card></div>
    <flux:card><div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Exit date</flux:table.column><flux:table.column>Trade date</flux:table.column><flux:table.column>Contract</flux:table.column><flux:table.column>Contracts</flux:table.column><flux:table.column>Gross</flux:table.column><flux:table.column>Commission</flux:table.column><flux:table.column>VAT</flux:table.column><flux:table.column>Net</flux:table.column></flux:table.columns><flux:table.rows>@forelse($this->exits as $exit)<flux:table.row :key="$exit->id"><flux:table.cell>{{ thai_date($exit->exit_date) }}</flux:table.cell><flux:table.cell>{{ thai_date($exit->trade->trade_date) }}</flux:table.cell><flux:table.cell variant="strong">{{ $exit->trade->contract }}</flux:table.cell><flux:table.cell>{{ $exit->exit_contracts }}</flux:table.cell><flux:table.cell>{{ number_format($exit->gross_profit,2) }}</flux:table.cell><flux:table.cell>{{ number_format($this->commissionFor($exit),2) }}</flux:table.cell><flux:table.cell>{{ number_format($this->vatFor($exit),2) }}</flux:table.cell><flux:table.cell><span class="{{ $this->netFor($exit) >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($this->netFor($exit),2) }}</span></flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell colspan="8"><div class="py-8 text-center text-zinc-500">No exits found.</div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div><div class="mt-4">{{ $this->exits->links() }}</div></flux:card>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="lg">Breakdown by contract</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Contract</flux:table.column>
                        <flux:table.column>Exits</flux:table.column>
                        <flux:table.column>Win rate</flux:table.column>
                        <flux:table.column>Net P/L</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->contractSummary as $row)
                            <flux:table.row :key="$row['contract']">
                                <flux:table.cell variant="strong">{{ $row['contract'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['trades'] }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($row['win_rate'], 2) }}%</flux:table.cell>
                                <flux:table.cell class="{{ $row['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($row['net'], 2) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="4"><div class="py-6 text-center text-zinc-500">No data.</div></flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg">Monthly P/L summary</flux:heading>
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Month (พ.ศ.)</flux:table.column>
                        <flux:table.column>Exits</flux:table.column>
                        <flux:table.column>Gross</flux:table.column>
                        <flux:table.column>Cost</flux:table.column>
                        <flux:table.column>Net</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->monthlySummary as $row)
                            <flux:table.row :key="$row['period']">
                                <flux:table.cell variant="strong">{{ $row['label'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['trades'] }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($row['gross'], 2) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($row['cost'], 2) }}</flux:table.cell>
                                <flux:table.cell class="{{ $row['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($row['net'], 2) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="5"><div class="py-6 text-center text-zinc-500">No data.</div></flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">Yearly P/L summary</flux:heading>
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Year (พ.ศ.)</flux:table.column>
                    <flux:table.column>Exits</flux:table.column>
                    <flux:table.column>Gross</flux:table.column>
                    <flux:table.column>Cost</flux:table.column>
                    <flux:table.column>Net</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->yearlySummary as $row)
                        <flux:table.row :key="$row['year']">
                            <flux:table.cell variant="strong">{{ $row['year'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['trades'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['gross'], 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['cost'], 2) }}</flux:table.cell>
                            <flux:table.cell class="{{ $row['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($row['net'], 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="5"><div class="py-6 text-center text-zinc-500">No data.</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
