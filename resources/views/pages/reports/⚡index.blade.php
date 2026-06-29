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
    <flux:card class="space-y-4"><div class="grid gap-4 md:grid-cols-3"><flux:input type="date" wire:model.live="from" label="From (AD)"/><flux:input type="date" wire:model.live="to" label="To (AD)"/><flux:input wire:model.live.debounce.300ms="contract" label="Contract" placeholder="ค้นหาด้วย Symbol สัญญา เช่น S50"/></div></flux:card>
    <div class="grid gap-4 md:grid-cols-3"><flux:card><flux:text>Gross P/L</flux:text><flux:heading size="xl">{{ number_format($this->totals['gross'],2) }}</flux:heading></flux:card><flux:card><flux:text>Total Cost</flux:text><flux:heading size="xl">{{ number_format($this->totals['cost'],2) }}</flux:heading></flux:card><flux:card><flux:text>Net P/L</flux:text><flux:heading size="xl">{{ number_format($this->totals['net'],2) }}</flux:heading></flux:card></div>
    <flux:card><div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Exit date</flux:table.column><flux:table.column>Contract</flux:table.column><flux:table.column>Contracts</flux:table.column><flux:table.column>Gross</flux:table.column><flux:table.column>Commission</flux:table.column><flux:table.column>VAT</flux:table.column><flux:table.column>Net</flux:table.column></flux:table.columns><flux:table.rows>@forelse($this->exits as $exit)<flux:table.row :key="$exit->id"><flux:table.cell>{{ thai_date($exit->exit_date) }}</flux:table.cell><flux:table.cell variant="strong">{{ $exit->trade->contract }}</flux:table.cell><flux:table.cell>{{ $exit->exit_contracts }}</flux:table.cell><flux:table.cell>{{ number_format($exit->gross_profit,2) }}</flux:table.cell><flux:table.cell>{{ number_format($this->commissionFor($exit),2) }}</flux:table.cell><flux:table.cell>{{ number_format($this->vatFor($exit),2) }}</flux:table.cell><flux:table.cell><span class="{{ $this->netFor($exit) >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($this->netFor($exit),2) }}</span></flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell colspan="7"><div class="py-8 text-center text-zinc-500">No exits found.</div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div><div class="mt-4">{{ $this->exits->links() }}</div></flux:card>
</div>
