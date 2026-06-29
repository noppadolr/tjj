<?php

use App\Enums\PositionType;
use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradeExit;
use App\Models\TradingAccount;
use App\Support\TradeCostCalculator;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function dashboard(): array
    {
        $account = TradingAccount::where('is_active', true)->first();
        $initial = (float) ($account?->initial_balance ?? 0);
        $currentBalance = (float) ($account?->current_balance ?? 0);
        $exits = TradeExit::with(['trade', 'tradeCommission'])->orderBy('exit_date')->orderBy('id')->get();
        $trades = Trade::with('exits')->get();
        $net = (float)$exits->sum(fn($exit)=>$this->netFor($exit)); $grossProfit=(float)$exits->where('gross_profit','>',0)->sum('gross_profit'); $grossLoss=(float)$exits->where('gross_profit','<',0)->sum('gross_profit');
        $profits=$exits->filter(fn($exit)=>$this->netFor($exit)>0); $losses=$exits->filter(fn($exit)=>$this->netFor($exit)<0);
        $snapshots=EquitySnapshot::orderBy('snapshot_date')->orderBy('id')->get(); $peak=$initial; $maxDrawdown=0; $minBalance=$initial;
        foreach ($snapshots as $snapshot) { $balance=(float)$snapshot->balance_after; $peak=max($peak,$balance); $minBalance=min($minBalance,$balance); $maxDrawdown=max($maxDrawdown,$peak-$balance); }
        $long=$trades->where('position_type',PositionType::Long); $short=$trades->where('position_type',PositionType::Short);
        $tradeNet=fn($trade)=>(float)$trade->exits->sum(fn($exit)=>$this->netFor($exit));
        $longWins=$long->filter(fn($t)=>$tradeNet($t)>0)->count(); $shortWins=$short->filter(fn($t)=>$tradeNet($t)>0)->count();
        $longLosses=$long->filter(fn($t)=>$tradeNet($t)<0)->count(); $shortLosses=$short->filter(fn($t)=>$tradeNet($t)<0)->count();
        $streak=function(bool $winning) use($exits){ $best=$current=0; foreach($exits as $exit){ $net=$this->netFor($exit); if($winning ? $net>0 : $net<0){$current++;$best=max($best,$current);}else{$current=0;}} return $best; };
        $totalCommission=(float)$exits->sum(fn($exit)=>$this->commissionFor($exit)); $totalVat=(float)$exits->sum(fn($exit)=>$this->vatFor($exit)); $totalCost=round($totalCommission+$totalVat,2);
        return [
            'metrics'=>[
                ['Initial Balance',$initial,'money'],['Current Balance',$currentBalance,'money'],['Total Net Profit',$net,'money'],['Total Net Profit %',$initial ? $net/$initial*100 : 0,'percent'],['Gross Profit',$grossProfit,'money'],['Gross Loss',$grossLoss,'money'],
                ['Absolute Drawdown',max(0,$initial-$minBalance),'money'],['Maximal Drawdown',$maxDrawdown,'money'],['Expected Payoff',$trades->count() ? $net/$trades->count() : 0,'money'],['Total Trade',$trades->count(),'number'],['Long Trades',$long->count(),'number'],['Short Trades',$short->count(),'number'],
                ['Long Position (% won)',$long->count() ? $longWins/$long->count()*100 : 0,'percent'],['Profit Trades (% of Total Long)',$long->count() ? $longWins/$long->count()*100 : 0,'percent'],['Loss Trades (% of Total Long)',$long->count() ? $longLosses/$long->count()*100 : 0,'percent'],
                ['Short Position (% won)',$short->count() ? $shortWins/$short->count()*100 : 0,'percent'],['Profit Trades (% of Total Short)',$short->count() ? $shortWins/$short->count()*100 : 0,'percent'],['Loss Trades (% of Total Short)',$short->count() ? $shortLosses/$short->count()*100 : 0,'percent'],
                ['Largest Profit Trade',$profits->count() ? (float)$profits->max(fn($exit)=>$this->netFor($exit)) : 0,'money'],['Average Profit Trade',$profits->count() ? (float)$profits->avg(fn($exit)=>$this->netFor($exit)) : 0,'money'],['Maximal Consecutive Profit Times',$streak(true),'number'],['Maximal Consecutive Loss Times',$streak(false),'number'],
                ['Total Commission',$totalCommission,'money'],['Total VAT',$totalVat,'money'],['Total Cost',$totalCost,'money'],
            ],
            'equity'=>['labels'=>$snapshots->map(fn($s)=>thai_date($s->snapshot_date))->values(),'values'=>$snapshots->pluck('balance_after')->map(fn($v)=>(float)$v)->values()],
            'positions'=>['labels'=>$trades->sortBy('trade_date')->map(fn($t)=>thai_date($t->trade_date))->values(),'values'=>$trades->sortBy('trade_date')->pluck('total_contracts')->values()],
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
    <div><flux:heading size="xl">Dashboard</flux:heading><flux:text>Performance overview for the active trading account.</flux:text></div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($this->dashboard['metrics'] as [$label,$value,$format])
            <flux:card><flux:text class="text-sm">{{ $label }}</flux:text><flux:heading size="xl" class="mt-2 {{ $value < 0 ? 'text-red-600' : '' }}">{{ $format === 'money' ? number_format($value,2) : ($format === 'percent' ? number_format($value,2).'%' : number_format($value)) }}</flux:heading></flux:card>
        @endforeach
    </div>
    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card><flux:heading size="lg">Equity Curve</flux:heading><div id="equity-chart" wire:ignore class="mt-4 min-h-80"></div></flux:card>
        <flux:card><flux:heading size="lg">Position Size</flux:heading><div id="position-chart" wire:ignore class="mt-4 min-h-80"></div></flux:card>
    </div>
</div>

@script
<script>
    const data = @js($this->dashboard);
    new ApexCharts(document.querySelector('#equity-chart'), {chart:{type:'area',height:320,toolbar:{show:false}},series:[{name:'Balance',data:data.equity.values}],xaxis:{categories:data.equity.labels},stroke:{curve:'smooth',width:3},colors:['#2563eb'],dataLabels:{enabled:false},noData:{text:'No exits yet'}}).render();
    new ApexCharts(document.querySelector('#position-chart'), {chart:{type:'bar',height:320,toolbar:{show:false}},series:[{name:'Contracts',data:data.positions.values}],xaxis:{categories:data.positions.labels},colors:['#0d9488'],dataLabels:{enabled:false},noData:{text:'No trades yet'}}).render();
</script>
@endscript
