<?php

use App\Enums\PositionType;
use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradeExit;
use App\Models\TradingAccount;
use App\Support\TradeCostCalculator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $from = '';
    public string $to = '';

    public function updatedFrom(): void
    {
        unset($this->dashboard);
        $this->dispatch('dashboard-refreshed', dashboard: $this->dashboard);
    }

    public function updatedTo(): void
    {
        unset($this->dashboard);
        $this->dispatch('dashboard-refreshed', dashboard: $this->dashboard);
    }

    public function clearFilter(): void
    {
        $this->reset(['from', 'to']);
        unset($this->dashboard);
        $this->dispatch('dashboard-refreshed', dashboard: $this->dashboard);
    }

    #[Computed]
    public function dashboard(): array
    {
        $account = TradingAccount::where('is_active', true)->first();
        $accountId = $account?->id;
        $initial = (float) ($account?->initial_balance ?? 0);
        $currentBalance = (float) ($account?->current_balance ?? 0);

        $exits = TradeExit::with(['trade', 'tradeCommission'])
            ->whereHas('trade', fn (Builder $query) => $query->where('trading_account_id', $accountId))
            ->when($this->from, fn (Builder $query) => $query->whereDate('exit_date', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('exit_date', '<=', $this->to))
            ->orderBy('exit_date')->orderBy('id')->get();

        $trades = Trade::with('exits')
            ->where('trading_account_id', $accountId)
            ->when($this->from, fn (Builder $query) => $query->whereDate('trade_date', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('trade_date', '<=', $this->to))
            ->get();

        $snapshots = EquitySnapshot::where('trading_account_id', $accountId)
            ->when($this->from, fn (Builder $query) => $query->whereDate('snapshot_date', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('snapshot_date', '<=', $this->to))
            ->orderBy('snapshot_date')->orderBy('id')->get();

        $periodStart = $this->from ? (float) ($snapshots->first()?->balance_before ?? $currentBalance) : $initial;

        $net = (float)$exits->sum(fn($exit)=>$this->netFor($exit)); $grossProfit=(float)$exits->where('gross_profit','>',0)->sum('gross_profit'); $grossLoss=(float)$exits->where('gross_profit','<',0)->sum('gross_profit');
        $profits=$exits->filter(fn($exit)=>$this->netFor($exit)>0); $losses=$exits->filter(fn($exit)=>$this->netFor($exit)<0);
        $peak=$periodStart; $maxDrawdown=0; $minBalance=$periodStart;
        foreach ($snapshots as $snapshot) { $balance=(float)$snapshot->balance_after; $peak=max($peak,$balance); $minBalance=min($minBalance,$balance); $maxDrawdown=max($maxDrawdown,$peak-$balance); }
        $long=$trades->where('position_type',PositionType::Long); $short=$trades->where('position_type',PositionType::Short);
        $tradeNet=fn($trade)=>(float)$trade->exits->sum(fn($exit)=>$this->netFor($exit));
        $longWins=$long->filter(fn($t)=>$tradeNet($t)>0)->count(); $shortWins=$short->filter(fn($t)=>$tradeNet($t)>0)->count();
        $longLosses=$long->filter(fn($t)=>$tradeNet($t)<0)->count(); $shortLosses=$short->filter(fn($t)=>$tradeNet($t)<0)->count();
        $streak=function(bool $winning) use($exits){ $best=$current=0; foreach($exits as $exit){ $net=$this->netFor($exit); if($winning ? $net>0 : $net<0){$current++;$best=max($best,$current);}else{$current=0;}} return $best; };
        $totalCommission=(float)$exits->sum(fn($exit)=>$this->commissionFor($exit)); $totalVat=(float)$exits->sum(fn($exit)=>$this->vatFor($exit)); $totalCost=round($totalCommission+$totalVat,2);

        $contracts = $trades->groupBy('contract')->map(function ($groupTrades, $contract) use ($tradeNet) {
            $closedTrades = $groupTrades->filter(fn ($trade) => $trade->exits->isNotEmpty());
            $wins = $closedTrades->filter(fn ($trade) => $tradeNet($trade) > 0)->count();

            return [
                'contract' => $contract,
                'trades' => $groupTrades->count(),
                'win_rate' => $closedTrades->count() ? $wins / $closedTrades->count() * 100 : 0,
                'net' => (float) $groupTrades->sum(fn ($trade) => $tradeNet($trade)),
            ];
        })->sortByDesc('net')->values();

        return [
            'metrics'=>[
                ['Initial Balance',$initial,'money'],['Current Balance',$currentBalance,'money'],['Total Net Profit',$net,'money'],['Total Net Profit %',$initial ? $net/$initial*100 : 0,'percent'],['Gross Profit',$grossProfit,'money'],['Gross Loss',$grossLoss,'money'],
                ['Absolute Drawdown',max(0,$periodStart-$minBalance),'money'],['Maximal Drawdown',$maxDrawdown,'money'],['Expected Payoff',$trades->count() ? $net/$trades->count() : 0,'money'],['Total Trade',$trades->count(),'number'],['Long Trades',$long->count(),'number'],['Short Trades',$short->count(),'number'],
                ['Long Position (% won)',$long->count() ? $longWins/$long->count()*100 : 0,'percent'],['Profit Trades (% of Total Long)',$long->count() ? $longWins/$long->count()*100 : 0,'percent'],['Loss Trades (% of Total Long)',$long->count() ? $longLosses/$long->count()*100 : 0,'percent'],
                ['Short Position (% won)',$short->count() ? $shortWins/$short->count()*100 : 0,'percent'],['Profit Trades (% of Total Short)',$short->count() ? $shortWins/$short->count()*100 : 0,'percent'],['Loss Trades (% of Total Short)',$short->count() ? $shortLosses/$short->count()*100 : 0,'percent'],
                ['Largest Profit Trade',$profits->count() ? (float)$profits->max(fn($exit)=>$this->netFor($exit)) : 0,'money'],['Average Profit Trade',$profits->count() ? (float)$profits->avg(fn($exit)=>$this->netFor($exit)) : 0,'money'],['Maximal Consecutive Profit Times',$streak(true),'number'],['Maximal Consecutive Loss Times',$streak(false),'number'],
                ['Total Commission',$totalCommission,'money'],['Total VAT',$totalVat,'money'],['Total Cost',$totalCost,'money'],
            ],
            'equity'=>['labels'=>$snapshots->map(fn($s)=>thai_date($s->snapshot_date))->values(),'values'=>$snapshots->pluck('balance_after')->map(fn($v)=>(float)$v)->values()],
            'positions'=>['labels'=>$trades->sortBy('trade_date')->map(fn($t)=>thai_date($t->trade_date))->values(),'values'=>$trades->sortBy('trade_date')->pluck('total_contracts')->values()],
            'contracts'=>$contracts,
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

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><flux:heading size="xl">Dashboard</flux:heading><flux:text>Performance overview for the active trading account.</flux:text></div>
    </div>

    <flux:card class="space-y-4">
        <div class="grid gap-4 md:grid-cols-3">
            @include('partials.thai-date-picker', ['field' => 'from', 'label' => 'From (พ.ศ.)', 'clearable' => true, 'live' => true, 'placeholder' => 'ทุกวันที่'])
            @include('partials.thai-date-picker', ['field' => 'to', 'label' => 'To (พ.ศ.)', 'clearable' => true, 'live' => true, 'placeholder' => 'ทุกวันที่'])
            <div class="flex items-end">
                <flux:button wire:click="clearFilter">Clear filter</flux:button>
            </div>
        </div>
    </flux:card>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($this->dashboard['metrics'] as [$label,$value,$format])
            <flux:card><flux:text class="text-sm">{{ $label }}</flux:text><flux:heading size="xl" class="mt-2 {{ $value < 0 ? 'text-red-600' : '' }}">{{ $format === 'money' ? number_format($value,2) : ($format === 'percent' ? number_format($value,2).'%' : number_format($value)) }}</flux:heading></flux:card>
        @endforeach
    </div>
    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card><flux:heading size="lg">Equity Curve</flux:heading><div id="equity-chart" wire:ignore class="mt-4 min-h-80"></div></flux:card>
        <flux:card><flux:heading size="lg">Position Size</flux:heading><div id="position-chart" wire:ignore class="mt-4 min-h-80"></div></flux:card>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">Breakdown by contract</flux:heading>
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Contract</flux:table.column>
                    <flux:table.column>Trades</flux:table.column>
                    <flux:table.column>Win rate</flux:table.column>
                    <flux:table.column>Net P/L</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->dashboard['contracts'] as $row)
                        <flux:table.row :key="$row['contract']">
                            <flux:table.cell variant="strong">{{ $row['contract'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['trades'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['win_rate'], 2) }}%</flux:table.cell>
                            <flux:table.cell class="{{ $row['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($row['net'], 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="4"><div class="py-6 text-center text-zinc-500">No trades in this period.</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>

@script
<script>
    const data = @js($this->dashboard);
    let equityChart = new ApexCharts(document.querySelector('#equity-chart'), {chart:{type:'area',height:320,toolbar:{show:false}},series:[{name:'Balance',data:data.equity.values}],xaxis:{categories:data.equity.labels},stroke:{curve:'smooth',width:3},colors:['#2563eb'],dataLabels:{enabled:false},noData:{text:'No exits yet'}});
    let positionChart = new ApexCharts(document.querySelector('#position-chart'), {chart:{type:'bar',height:320,toolbar:{show:false}},series:[{name:'Contracts',data:data.positions.values}],xaxis:{categories:data.positions.labels},colors:['#0d9488'],dataLabels:{enabled:false},noData:{text:'No trades yet'}});
    equityChart.render();
    positionChart.render();

    $wire.on('dashboard-refreshed', (event) => {
        const refreshed = event.dashboard;
        equityChart.updateOptions({series:[{name:'Balance',data:refreshed.equity.values}],xaxis:{categories:refreshed.equity.labels}});
        positionChart.updateOptions({series:[{name:'Contracts',data:refreshed.positions.values}],xaxis:{categories:refreshed.positions.labels}});
    });
</script>
@endscript
