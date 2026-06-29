<?php

use App\Enums\PositionType;
use App\Enums\TradeStatus;
use App\Livewire\BaseIndexComponent;
use App\Models\CommissionRate;
use App\Models\Contract;
use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradeExit;
use App\Models\TradingAccount;
use App\Support\TradeCostCalculator;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

new class extends BaseIndexComponent {
    public string $search = '';
    public ?int $editingId = null;
    public ?int $closingId = null;
    public bool $showTradeModal = false;
    public bool $showCloseModal = false;
    public bool $showDeleteModal = false;
    public bool $showEditExitModal = false;
    public bool $showCancelExitModal = false;
    public ?int $editingExitId = null;
    public float|string $editingExitPrice = '';
    public string $editingExitLabel = '';
    public ?int $cancellingExitId = null;
    public string $cancellingExitLabel = '';
    public ?int $deletingId = null;
    public string $deletingLabel = '';
    public string $trade_date = '';
    public string $contract = '';
    public string $position_type = 'long';
    public int|string $total_contracts = 1;
    public float|string $entry_price = '';
    public string $entry_date = '';
    public ?string $note = null;
    public string $exit_date = '';
    public ?string $exit_time = null;
    public float|string $exit_price = '';
    public int|string $exit_contracts = 1;
    public int $closingTotal = 0;
    public int $closingRemaining = 0;

    public function mount(): void
    {
        $this->resetTradeForm();
        $this->resetExitForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function trades()
    {
        return $this->getRowsQuery()->paginate($this->perPage);
    }

    protected function getRowsQuery(): Builder
    {
        return Trade::query()
            ->with('exits.tradeCommission')
            ->when($this->search, fn(Builder $query) => $query->where('contract', 'like', '%' . $this->search . '%'))
            ->latest('trade_date')
            ->latest('id');
    }

    #[Computed]
    public function availableContracts()
    {
        return Contract::where('is_active', true)->orderBy('symbol')->get();
    }

    public function create(): void
    {
        $this->resetTradeForm();
        $this->showTradeModal = true;
    }

    public function edit(int $id): void
    {
        $trade = Trade::findOrFail($id);
        $this->editingId = $trade->id;
        $this->trade_date = $trade->trade_date->toDateString();
        $this->contract = $trade->contract;
        $this->position_type = $trade->position_type->value;
        $this->total_contracts = $trade->total_contracts;
        $this->entry_price = $trade->entry_price;
        $this->entry_date = $trade->entry_date->toDateString();
        $this->note = $trade->note;
        $this->showTradeModal = true;
    }

    public function save(): void
    {
        try {
            $tradeDate = Carbon::parse($this->trade_date)->toDateString();
        } catch (\Throwable) {
            $this->addError('trade_date', 'กรุณาเลือก Trade date จาก date picker');

            return;
        }

        try {
            $entryDate = Carbon::parse($this->entry_date)->toDateString();
        } catch (\Throwable) {
            $this->addError('entry_date', 'กรุณาเลือก Entry date จาก date picker');

            return;
        }

        $data = $this->validate([
            'trade_date' => 'required|date', 'contract' => 'required|string|max:50', 'position_type' => 'required|in:long,short', 'total_contracts' => 'required|integer|min:1',
            'entry_price' => 'required|numeric|min:0', 'entry_date' => 'required|date', 'note' => 'nullable|string|max:5000',
        ]);
        $data['trade_date'] = $tradeDate;
        $data['entry_date'] = $entryDate;
        $data['contract'] = strtoupper(trim($data['contract']));
        $contract = Contract::where('symbol', $data['contract'])->where('is_active', true)->first();
        if (!$contract) throw ValidationException::withMessages(['contract' => 'Please select an active saved contract.']);
        $data['contract_id'] = $contract->id;
        if ($this->editingId) {
            $trade = Trade::findOrFail($this->editingId);
            if ((int)$data['total_contracts'] < $trade->closedContracts()) throw ValidationException::withMessages(['total_contracts' => 'Cannot be less than contracts already closed.']);
            $trade->update($data);
        } else {
            $account = TradingAccount::where('is_active', true)->first();
            if (!$account) throw ValidationException::withMessages(['contract' => 'No active trading account exists.']);
            $account->trades()->create($data + ['status' => TradeStatus::Open]);
        }
        $this->showTradeModal = false;
        unset($this->trades);
        Flux::toast($this->editingId ? 'Trade updated.' : 'Trade created.', variant: 'success');
    }

    public function openClose(int $id): void
    {
        $trade = Trade::findOrFail($id);
        if ($trade->remainingContracts() < 1) {
            Flux::toast('This trade is already closed.', variant: 'warning');
            return;
        }
        $this->resetExitForm();
        $this->closingId = $trade->id;
        $this->closingTotal = $trade->total_contracts;
        $this->closingRemaining = $trade->remainingContracts();
        $this->exit_contracts = $trade->remainingContracts();
        $this->showCloseModal = true;
    }

    public function closeTrade(): void
    {
        $this->validate(['closingId' => 'required|exists:trades,id', 'exit_date' => 'required|date', 'exit_time' => 'nullable|date_format:H:i', 'exit_price' => 'required|numeric|min:0', 'exit_contracts' => 'required|integer|min:1']);

        DB::transaction(function () {
            $trade = Trade::with(['tradingAccount', 'contractDefinition'])->lockForUpdate()->findOrFail($this->closingId);
            $remaining = $trade->remainingContracts();
            if ((int)$this->exit_contracts > $remaining) throw ValidationException::withMessages(['exit_contracts' => "Only {$remaining} contracts remain."]);

            $contract = strtoupper((string)$trade->contract);
            $underlying = preg_replace('/[FGHJKMNQUVXZ]\d{2}$/', '', $contract) ?: $contract;
            $rate = CommissionRate::query()
                ->where('is_active', true)
                ->whereDate('effective_date', '<=', $this->exit_date)
                ->whereIn('contract', collect([$contract, $underlying])->filter()->unique()->values())
                ->orderByRaw('case when contract = ? then 0 else 1 end', [$contract])
                ->latest('effective_date')
                ->first();
            $commissionRate = (float)($rate?->commission_per_contract ?? 0);
            $vatPercent = (float)($rate?->vat_percent ?? 0);
            $direction = $trade->position_type === PositionType::Long ? 1 : -1;
            $multiplier = (float)($trade->contractDefinition?->multiplier ?? 200);
            $gross = round(((float)$this->exit_price - (float)$trade->entry_price) * (int)$this->exit_contracts * $multiplier * $direction, 2);
            $commission = round((int)$this->exit_contracts * $commissionRate * 2, 2);
            $vat = round($commission * $vatPercent / 100, 2);
            $cost = round($commission + $vat, 2);
            $net = round($gross - $cost, 2);

            $exit = $trade->exits()->create(['exit_date' => $this->exit_date, 'exit_time' => $this->exit_time ?: null, 'exit_price' => $this->exit_price, 'exit_contracts' => $this->exit_contracts, 'gross_profit' => $gross, 'commission' => $commission, 'vat' => $vat, 'total_cost' => $cost, 'net_profit' => $net]);
            $exit->tradeCommission()->create(['commission_rate_id' => $rate?->id, 'commission' => $commission, 'vat' => $vat, 'total_cost' => $cost]);
            $closed = $trade->closedContracts();
            $trade->update(['status' => $closed >= $trade->total_contracts ? TradeStatus::Closed : ($closed > 0 ? TradeStatus::Partial : TradeStatus::Open)]);

            $account = TradingAccount::lockForUpdate()->findOrFail($trade->trading_account_id);
            $before = (float)$account->current_balance;
            $after = round($before + $net, 2);
            EquitySnapshot::create(['trading_account_id' => $account->id, 'trade_id' => $trade->id, 'trade_exit_id' => $exit->id, 'balance_before' => $before, 'net_profit' => $net, 'balance_after' => $after, 'snapshot_date' => $this->exit_date]);
            $account->update(['current_balance' => $after]);
        });

        $this->showCloseModal = false;
        unset($this->trades);
        Flux::toast('Exit saved and balance updated.', variant: 'success');
    }

    public function editExit(int $id): void
    {
        $exit = \App\Models\TradeExit::with('trade')->findOrFail($id);
        $this->editingExitId = $exit->id;
        $this->editingExitPrice = $exit->exit_price;
        $this->editingExitLabel = $exit->trade->contract . ' · ' . thai_date($exit->exit_date) . ' · ' . $exit->exit_contracts . ' contracts';
        $this->showEditExitModal = true;
    }

    public function updateExitPrice(): void
    {
        $this->validate([
            'editingExitId' => 'required|exists:trade_exits,id',
            'editingExitPrice' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () {
            $exit = \App\Models\TradeExit::with(['trade.contractDefinition', 'trade.tradingAccount', 'tradeCommission', 'equitySnapshot'])
                ->lockForUpdate()
                ->findOrFail($this->editingExitId);
            $trade = $exit->trade;
            $account = TradingAccount::lockForUpdate()->findOrFail($trade->trading_account_id);
            $oldNet = (float)$exit->net_profit;
            $direction = $trade->position_type === PositionType::Long ? 1 : -1;
            $multiplier = (float)($trade->contractDefinition?->multiplier ?? 200);
            $gross = round(((float)$this->editingExitPrice - (float)$trade->entry_price) * $exit->exit_contracts * $multiplier * $direction, 2);
            $cost = $this->costCalculator()->totalCostFor($exit, $trade);
            $net = round($gross - $cost, 2);

            $exit->update([
                'exit_price' => $this->editingExitPrice,
                'gross_profit' => $gross,
                'net_profit' => $net,
            ]);

            $snapshot = $exit->equitySnapshot
                ?? $account->equitySnapshots()
                    ->where('trade_id', $trade->id)
                    ->whereDate('snapshot_date', $exit->exit_date)
                    ->where('net_profit', $oldNet)
                    ->first();

            if ($snapshot) {
                $snapshot->update(['trade_exit_id' => $exit->id, 'net_profit' => $net]);
            } else {
                EquitySnapshot::create([
                    'trading_account_id' => $account->id,
                    'trade_id' => $trade->id,
                    'trade_exit_id' => $exit->id,
                    'balance_before' => 0,
                    'net_profit' => $net,
                    'balance_after' => 0,
                    'snapshot_date' => $exit->exit_date,
                ]);
            }

            $balance = (float)$account->initial_balance;
            foreach ($account->equitySnapshots()->orderBy('snapshot_date')->orderBy('id')->lockForUpdate()->get() as $item) {
                $before = $balance;
                $balance = round($before + (float)$item->net_profit, 2);
                $item->update(['balance_before' => $before, 'balance_after' => $balance]);
            }
            $account->update(['current_balance' => $balance]);
        });

        $this->showEditExitModal = false;
        $this->editingExitId = null;
        unset($this->trades);
        Flux::toast('Exit price and account balance updated.', variant: 'success');
    }

    public function confirmCancelExit(int $id): void
    {
        $exit = \App\Models\TradeExit::with('trade')->findOrFail($id);
        $this->cancellingExitId = $exit->id;
        $this->cancellingExitLabel = $exit->trade->contract . ' · ' . thai_date($exit->exit_date) . ' · ปิด ' . $exit->exit_contracts . ' สัญญา @ ' . number_format((float)$exit->exit_price, 2);
        $this->showCancelExitModal = true;
    }

    public function cancelExit(): void
    {
        $this->validate(['cancellingExitId' => 'required|exists:trade_exits,id']);

        DB::transaction(function () {
            $exit = \App\Models\TradeExit::with(['trade', 'tradeCommission', 'equitySnapshot'])
                ->lockForUpdate()
                ->findOrFail($this->cancellingExitId);
            $trade = $exit->trade;
            $account = TradingAccount::lockForUpdate()->findOrFail($trade->trading_account_id);

            $snapshot = $exit->equitySnapshot
                ?? $account->equitySnapshots()
                    ->where('trade_id', $trade->id)
                    ->whereDate('snapshot_date', $exit->exit_date)
                    ->where('net_profit', $exit->net_profit)
                    ->first();

            $snapshot?->delete();
            $exit->tradeCommission?->delete();
            $exit->delete();

            $closed = $trade->closedContracts();
            $trade->update([
                'status' => $closed === 0
                    ? TradeStatus::Open
                    : ($closed >= $trade->total_contracts ? TradeStatus::Closed : TradeStatus::Partial),
            ]);

            $balance = (float)$account->initial_balance;
            foreach ($account->equitySnapshots()->orderBy('snapshot_date')->orderBy('id')->lockForUpdate()->get() as $item) {
                $before = $balance;
                $balance = round($before + (float)$item->net_profit, 2);
                $item->update(['balance_before' => $before, 'balance_after' => $balance]);
            }
            $account->update(['current_balance' => $balance]);
        });

        $this->showCancelExitModal = false;
        $this->cancellingExitId = null;
        unset($this->trades);
        Flux::toast('Cancelled the contract close and recalculated the account.', variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $trade = Trade::findOrFail($id);
        $this->deletingId = $trade->id;
        $this->deletingLabel = $trade->contract . ' · ' . thai_date($trade->trade_date);
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        $this->showDeleteModal = false;
        $trade = Trade::findOrFail($this->deletingId);
        if ($trade->exits()->exists()) {
            Flux::toast('Trades with exits cannot be deleted.', variant: 'warning');
            return;
        }
        $trade->delete();
        $this->deletingId = null;
        $this->adjustPageAfterDelete();
        unset($this->trades);
        Flux::toast('Trade deleted.', variant: 'success');
    }

    public function totalCostFor(Trade $trade, TradeExit $exit): float
    {
        return $this->costCalculator()->totalCostFor($exit, $trade);
    }

    public function totalTradeCostFor(Trade $trade): float
    {
        $closingCost = $trade->exits->sum(fn(TradeExit $exit): float => $this->costCalculator()->closingTotalCostFor($exit, $trade));

        return round($this->costCalculator()->openingTotalCostFor($trade) + $closingCost, 2);
    }

    public function netTotalFor(Trade $trade): float
    {
        return round($trade->exits->sum(fn(TradeExit $exit): float => $this->costCalculator()->netFor($exit, $trade)), 2);
    }

    public function closeCommissionFor(Trade $trade, TradeExit $exit): float
    {
        return $this->costCalculator()->closingCommissionFor($exit, $trade);
    }

    public function closeVatFor(Trade $trade, TradeExit $exit): float
    {
        return $this->costCalculator()->closingVatFor($exit, $trade);
    }

    public function closeCostFor(Trade $trade, TradeExit $exit): float
    {
        return $this->costCalculator()->closingTotalCostFor($exit, $trade);
    }

    public function netFor(Trade $trade, TradeExit $exit): float
    {
        return $this->costCalculator()->netFor($exit, $trade);
    }

    private function costCalculator(): TradeCostCalculator
    {
        return app(TradeCostCalculator::class);
    }

    private function resetTradeForm(): void
    {
        $this->editingId = null;
        $this->trade_date = today()->toDateString();
        $this->entry_date = today()->toDateString();
        $this->contract = (string)(Contract::where('is_active', true)->orderBy('symbol')->value('symbol') ?? '');
        $this->position_type = 'long';
        $this->total_contracts = 1;
        $this->entry_price = '';
        $this->note = null;
    }

    private function resetExitForm(): void
    {
        $this->closingId = null;
        $this->exit_date = today()->toDateString();
        $this->exit_time = null;
        $this->exit_price = '';
        $this->exit_contracts = 1;
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Trades</flux:heading>
            <flux:text>Open, edit, and partially close TFEX positions.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">New trade</flux:button>
    </div>
    <flux:card class="space-y-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                    placeholder="ค้นหาด้วย Symbol สัญญา เช่น S50"/>
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Trade date</flux:table.column>
                    <flux:table.column>Contract</flux:table.column>
                    <flux:table.column>Position</flux:table.column>
                    <flux:table.column>Opened</flux:table.column>
                    <flux:table.column>Closed</flux:table.column>
                    <flux:table.column>Remaining</flux:table.column>
                    <flux:table.column>Entry</flux:table.column>
                    <flux:table.column>Cost</flux:table.column>
                    <flux:table.column>Net P/L</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->trades as $trade)
                        <flux:table.row :key="$trade->id">
                            <flux:table.cell>{{ thai_date($trade->trade_date) }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $trade->contract }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$trade->position_type->badgeColor()"
                                            size="sm">{{ $trade->position_type->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $trade->total_contracts }}</flux:table.cell>
                            <flux:table.cell>{{ $trade->closedContracts() }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $trade->remainingContracts() }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($trade->entry_price, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($this->totalTradeCostFor($trade), 2) }}</flux:table.cell>
                            <flux:table.cell
                                variant="strong"
                                class="{{ $trade->exits->isEmpty() ? '' : ($this->netTotalFor($trade) >= 0 ? 'text-green-600' : 'text-red-600') }}"
                            >{{ $trade->exits->isEmpty() ? '—' : number_format($this->netTotalFor($trade), 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$trade->status->badgeColor()"
                                            size="sm">{{ $trade->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" icon="eye" :href="route('trades.show', $trade)" wire:navigate>Detail
                                    </flux:button>
                                    <flux:button size="sm" icon="pencil" wire:click="edit({{ $trade->id }})">Edit
                                    </flux:button>
                                    <flux:button size="sm" icon="arrow-right-start-on-rectangle"
                                                 wire:click="openClose({{ $trade->id }})"
                                                 :disabled="$trade->remainingContracts() === 0">Close
                                    </flux:button>
                                    <flux:button size="sm" variant="danger" icon="trash"
                                                 wire:click="confirmDelete({{ $trade->id }})">Delete
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                        @if ($trade->exits->isNotEmpty())
                            <flux:table.row :key="'exits-'.$trade->id">
                                <flux:table.cell colspan="11">
                                    <div class="ml-6 space-y-2 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                        <div class="font-medium">Partial close history</div>
                                        @foreach($trade->exits as $exit)
                                            <div
                                                class="grid grid-cols-2 items-center gap-2 text-zinc-600 sm:grid-cols-9 dark:text-zinc-300">
                                                <span>{{ thai_date($exit->exit_date) }}</span><span>ปิด {{ $exit->exit_contracts }} สัญญา</span>
                                                <span>@ {{ number_format($exit->exit_price, 2) }}</span>
                                                <span>Commission {{ number_format($this->closeCommissionFor($trade, $exit), 2) }}</span>
                                                <span>VAT {{ number_format($this->closeVatFor($trade, $exit), 2) }}</span>
                                                <span class="">Close Cost {{ number_format($this->closeCostFor($trade, $exit), 2) }}</span>
                                                <span class="{{ $exit->gross_profit >= 0 ? 'text-green-600' : 'text-red-600' }}">Gross {{ number_format($exit->gross_profit, 2) }}</span>
                                                <span class="{{ $this->netFor($trade, $exit) >= 0 ? 'text-green-600' : 'text-red-600' }}">Net {{ number_format($this->netFor($trade, $exit), 2) }}</span>
                                                <span class="flex justify-end gap-1">
                                                    <flux:button size="sm" icon="pencil"
                                                                 wire:click="editExit({{ $exit->id }})">Edit price</flux:button><flux:button
                                                        size="sm" variant="danger" icon="x-mark"
                                                        wire:click="confirmCancelExit({{ $exit->id }})">ยกเลิกปิด</flux:button></span>
                                            </div>
                                        @endforeach</div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endif
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="11">
                                <div class="py-8 text-center text-zinc-500">No trades found.</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>{{ $this->trades->links() }}
    </flux:card>

    <flux:modal wire:model="showTradeModal" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit trade' : 'New trade' }}</flux:heading>
            <div class="grid grid-cols-2 gap-4">
                @include('partials.thai-date-picker', ['field' => 'trade_date', 'label' => 'Trade date (พ.ศ.)'])
                <flux:select wire:model="contract" label="Contract" placeholder="Select a contract"
                             required>@foreach($this->availableContracts as $item)
                        <flux:select.option
                            :value="$item->symbol">{{ $item->symbol }}{{ $item->name ? ' — '.$item->name : '' }}</flux:select.option>
                    @endforeach</flux:select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="position_type" label="Position">
                    <flux:select.option value="long">Long</flux:select.option>
                    <flux:select.option value="short">Short</flux:select.option>
                </flux:select>
                <flux:input type="number" min="1" wire:model="total_contracts" label="Contracts" required/>
            </div>
            <flux:input type="number" step="0.01" wire:model="entry_price" label="Entry price" required/>
            @include('partials.thai-date-picker', ['field' => 'entry_date', 'label' => 'Entry date (พ.ศ.)'])
            <flux:textarea wire:model="note" label="Note" rows="3"/>
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showTradeModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showCloseModal" class="md:w-[32rem]">
        <form wire:submit="closeTrade" class="space-y-5">
            <flux:heading size="lg">Partial close / exit</flux:heading>
            <div class="grid grid-cols-3 gap-3 rounded-xl bg-zinc-50 p-4 text-center dark:bg-zinc-800">
                <div>
                    <flux:text>Opened</flux:text>
                    <flux:heading>{{ $closingTotal }}</flux:heading>
                </div>
                <div>
                    <flux:text>Closed</flux:text>
                    <flux:heading>{{ $closingTotal - $closingRemaining }}</flux:heading>
                </div>
                <div>
                    <flux:text>Remaining</flux:text>
                    <flux:heading>{{ $closingRemaining }}</flux:heading>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                @include('partials.thai-date-picker', ['field' => 'exit_date', 'label' => 'Exit date (พ.ศ.)'])
                <flux:input type="time" wire:model="exit_time" label="Exit time"/>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" step="0.01" wire:model="exit_price" label="Exit price" required/>
                <flux:input type="number" min="1" :max="$closingRemaining" wire:model="exit_contracts"
                            label="Exit contracts"
                            description="หลังปิดจะเหลือ {{ max(0, $closingRemaining - (int) $exit_contracts) }} สัญญา"
                            required/>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showCloseModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save exit</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeleteModal" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete trade?</flux:heading>
                <flux:text class="mt-2">ต้องการลบรายการ <strong>{{ $deletingLabel }}</strong> หรือไม่?
                    การดำเนินการนี้ไม่สามารถย้อนกลับได้
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button variant="danger" icon="trash" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
    <flux:modal wire:model="showEditExitModal" class="md:w-[28rem]">
        <form wire:submit="updateExitPrice" class="space-y-6">
            <div>
                <flux:heading size="lg">แก้ไขราคาปิด</flux:heading>
                <flux:text class="mt-2">{{ $editingExitLabel }}</flux:text>
            </div>
            <flux:input type="number" min="0" step="0.01" wire:model="editingExitPrice" label="Exit price" required/>
            <flux:text>ระบบจะคำนวณกำไร/ขาดทุน Equity และยอดคงเหลือใหม่อัตโนมัติ</flux:text>
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showEditExitModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save correction</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showCancelExitModal" class="md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">ยกเลิกการปิดสัญญา?</flux:heading>
                <flux:text class="mt-2">{{ $cancellingExitLabel }}</flux:text>
                <flux:text class="mt-2">รายการปิด ค่าคอมมิชชัน และ Equity Snapshot นี้จะถูกยกเลิก
                    พร้อมคำนวณยอดคงเหลือใหม่
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCancelExitModal', false)">ไม่ยกเลิก</flux:button>
                <flux:button variant="danger" icon="x-mark" wire:click="cancelExit">ยืนยันยกเลิกการปิด</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
