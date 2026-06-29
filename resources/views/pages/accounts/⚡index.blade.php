<?php

use App\Enums\AccountTransactionType;
use App\Livewire\BaseIndexComponent;
use App\Models\AccountTransaction;
use App\Models\EquitySnapshot;
use App\Models\TradingAccount;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

new class extends BaseIndexComponent {
    public int $perPage = 5;

    public ?int $editingId = null;

    public bool $showModal = false;

    public bool $showDeleteModal = false;

    public bool $showTransactionModal = false;

    public bool $showDeleteTransactionModal = false;

    public ?int $deletingId = null;

    public ?int $editingTransactionId = null;

    public ?int $transactionAccountId = null;

    public ?int $deletingTransactionId = null;

    public string $deletingLabel = '';

    public string $transactionAccountLabel = '';

    public string $deletingTransactionLabel = '';

    public string $transaction_type = 'withdrawal';

    public float|string $transaction_amount = '';

    public string $transaction_date = '';

    public string $transaction_note = '';

    public string $name = '';

    public float|string $initial_balance = 100000;

    public bool $is_active = true;

    #[Computed]
    public function accounts()
    {
        return $this->getRowsQuery()->paginate($this->perPage);
    }

    protected function getRowsQuery(): Builder
    {
        return TradingAccount::query()
            ->with(['accountTransactions' => fn ($query) => $query->latest('transaction_date')->latest()])
            ->withCount('trades')
            ->withCount('accountTransactions')
            ->withSum(['accountTransactions as deposits_total' => fn ($query) => $query->where('type', AccountTransactionType::Deposit->value)], 'amount')
            ->withSum(['accountTransactions as withdrawals_total' => fn ($query) => $query->where('type', AccountTransactionType::Withdrawal->value)], 'amount')
            ->orderByDesc('is_active')
            ->orderBy('name');
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name']);
        $this->initial_balance = 100000;
        $this->is_active = ! TradingAccount::where('is_active', true)->exists();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $account = TradingAccount::findOrFail($id);
        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->initial_balance = $account->initial_balance;
        $this->is_active = $account->is_active;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => 'required|string|max:255',
            'initial_balance' => 'required|numeric|min:0|max:9999999999999.99',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($data) {
            if ($data['is_active']) {
                TradingAccount::query()
                    ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                    ->update(['is_active' => false]);
            }

            $account = $this->editingId
                ? TradingAccount::lockForUpdate()->findOrFail($this->editingId)
                : new TradingAccount;

            $account->fill([
                'name' => trim($data['name']),
                'initial_balance' => $data['initial_balance'],
                'is_active' => $data['is_active'],
            ]);

            $balance = round((float) $data['initial_balance'], 2);
            $account->current_balance = $balance;
            $account->save();

            $this->rebaseAccountBalance($account);
        });

        $wasEditing = filled($this->editingId);
        $this->showModal = false;
        unset($this->accounts);
        Flux::toast($wasEditing ? 'Trading account updated.' : 'Trading account created.', variant: 'success');
    }

    public function activate(int $id): void
    {
        DB::transaction(function () use ($id) {
            TradingAccount::query()->update(['is_active' => false]);
            TradingAccount::findOrFail($id)->update(['is_active' => true]);
        });

        unset($this->accounts);
        Flux::toast('Active trading account changed.', variant: 'success');
    }

    public function openTransaction(int $id, string $type = 'withdrawal'): void
    {
        $account = TradingAccount::findOrFail($id);
        $this->transactionAccountId = $account->id;
        $this->editingTransactionId = null;
        $this->transactionAccountLabel = $account->name;
        $this->transaction_type = in_array($type, [AccountTransactionType::Deposit->value, AccountTransactionType::Withdrawal->value], true)
            ? $type
            : AccountTransactionType::Withdrawal->value;
        $this->transaction_amount = '';
        $this->transaction_date = now()->toDateString();
        $this->transaction_note = '';
        $this->showTransactionModal = true;
    }

    public function editTransaction(int $id): void
    {
        $transaction = AccountTransaction::with('tradingAccount')->findOrFail($id);
        $this->editingTransactionId = $transaction->id;
        $this->transactionAccountId = $transaction->trading_account_id;
        $this->transactionAccountLabel = $transaction->tradingAccount->name;
        $this->transaction_type = $transaction->type->value;
        $this->transaction_amount = $transaction->amount;
        $this->transaction_date = $transaction->transaction_date->toDateString();
        $this->transaction_note = $transaction->note ?? '';
        $this->showTransactionModal = true;
    }

    public function saveTransaction(): void
    {
        $data = $this->validate([
            'transactionAccountId' => 'required|exists:trading_accounts,id',
            'transaction_type' => 'required|in:deposit,withdrawal',
            'transaction_amount' => 'required|numeric|min:0.01|max:9999999999999.99',
            'transaction_date' => 'required|date',
            'transaction_note' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($data) {
            $account = TradingAccount::lockForUpdate()->findOrFail($data['transactionAccountId']);
            $type = AccountTransactionType::from($data['transaction_type']);
            $amount = round((float) $data['transaction_amount'], 2);
            $transaction = $this->editingTransactionId
                ? AccountTransaction::with('equitySnapshot')->lockForUpdate()->findOrFail($this->editingTransactionId)
                : null;
            $availableBalance = (float) $account->current_balance - ($transaction?->signedAmount() ?? 0);

            if ($type === AccountTransactionType::Withdrawal && $amount > $availableBalance) {
                throw ValidationException::withMessages([
                    'transaction_amount' => 'ยอดถอนต้องไม่เกินยอดคงเหลือปัจจุบัน',
                ]);
            }

            $transaction = $transaction ?? new AccountTransaction(['trading_account_id' => $account->id]);
            $transaction->fill([
                'trading_account_id' => $account->id,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'],
                'note' => filled($data['transaction_note'] ?? '') ? trim($data['transaction_note']) : null,
            ]);
            $transaction->save();

            $snapshot = $transaction->equitySnapshot;

            if ($snapshot) {
                $snapshot->update([
                    'net_profit' => $type->signedAmount($amount),
                    'snapshot_date' => $data['transaction_date'],
                ]);
            } else {
                EquitySnapshot::create([
                    'trading_account_id' => $account->id,
                    'account_transaction_id' => $transaction->id,
                    'balance_before' => 0,
                    'net_profit' => $type->signedAmount($amount),
                    'balance_after' => 0,
                    'snapshot_date' => $data['transaction_date'],
                ]);
            }

            $this->rebaseAccountBalance($account);
        });

        $wasEditing = filled($this->editingTransactionId);
        $this->showTransactionModal = false;
        $this->editingTransactionId = null;
        $this->transactionAccountId = null;
        unset($this->accounts);
        Flux::toast($wasEditing ? 'Account cash transaction updated.' : 'Account cash transaction saved.', variant: 'success');
    }

    public function confirmDeleteTransaction(int $id): void
    {
        $transaction = AccountTransaction::with('tradingAccount')->findOrFail($id);
        $this->deletingTransactionId = $transaction->id;
        $this->deletingTransactionLabel = $transaction->tradingAccount->name.' · '.$transaction->type->label().' · '.number_format((float) $transaction->amount, 2);
        $this->showDeleteTransactionModal = true;
    }

    public function deleteTransactionConfirmed(): void
    {
        $this->showDeleteTransactionModal = false;

        DB::transaction(function () {
            $transaction = AccountTransaction::with(['tradingAccount', 'equitySnapshot'])
                ->lockForUpdate()
                ->findOrFail($this->deletingTransactionId);
            $account = TradingAccount::lockForUpdate()->findOrFail($transaction->trading_account_id);

            $transaction->equitySnapshot?->delete();
            $transaction->delete();

            $this->rebaseAccountBalance($account);
        });

        $this->deletingTransactionId = null;
        unset($this->accounts);
        Flux::toast('Account cash transaction deleted.', variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $account = TradingAccount::findOrFail($id);
        $this->deletingId = $account->id;
        $this->deletingLabel = $account->name;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        $this->showDeleteModal = false;
        $account = TradingAccount::withCount(['trades', 'equitySnapshots', 'accountTransactions'])->findOrFail($this->deletingId);

        if ($account->trades_count > 0 || $account->equity_snapshots_count > 0 || $account->account_transactions_count > 0) {
            Flux::toast('An account with trading history cannot be deleted.', variant: 'warning');

            return;
        }

        if ($account->is_active) {
            Flux::toast('Activate another account before deleting this one.', variant: 'warning');

            return;
        }

        $account->delete();
        $this->deletingId = null;
        $this->adjustPageAfterDelete();
        unset($this->accounts);
        Flux::toast('Trading account deleted.', variant: 'success');
    }

    private function rebaseAccountBalance(TradingAccount $account): void
    {
        $balance = round((float) $account->initial_balance, 2);

        foreach ($account->equitySnapshots()->orderBy('snapshot_date')->orderBy('id')->lockForUpdate()->get() as $snapshot) {
            $before = $balance;
            $balance = round($before + (float) $snapshot->net_profit, 2);
            $snapshot->update(['balance_before' => $before, 'balance_after' => $balance]);
        }

        $account->update(['current_balance' => $balance]);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Trading Accounts</flux:heading>
            <flux:text>ตั้งค่าบัญชี เงินทุนเริ่มต้น และบัญชีที่ใช้เปิดรายการใหม่</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">New account</flux:button>
    </div>

    <div class="grid gap-4">
        @foreach ($this->accounts as $account)
            <flux:card class="w-full space-y-5" :key="$account->id">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ $account->name }}</flux:heading>
                            <flux:badge :color="$account->is_active ? 'green' : 'zinc'" size="sm">
                                {{ $account->is_active ? 'Active' : 'Inactive' }}
                            </flux:badge>
                        </div>
                        <flux:text>{{ number_format($account->trades_count) }} trades</flux:text>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:button size="sm" wire:click="openTransaction({{ $account->id }}, 'deposit')">Deposit</flux:button>
                        <flux:button size="sm" wire:click="openTransaction({{ $account->id }}, 'withdrawal')">Withdraw</flux:button>
                        <flux:button size="sm" icon="pencil" wire:click="edit({{ $account->id }})">Edit</flux:button>
                    </div>
                </div>

                @php
                    $deposits = (float) ($account->deposits_total ?? 0);
                    $withdrawals = (float) ($account->withdrawals_total ?? 0);
                @endphp

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800">
                        <flux:text class="text-sm">เงินทุนเริ่มต้น</flux:text>
                        <flux:heading size="lg" class="mt-1">{{ number_format($account->initial_balance, 2) }}</flux:heading>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800">
                        <flux:text class="text-sm">ยอดคงเหลือปัจจุบัน</flux:text>
                        <flux:heading size="lg" class="mt-1">{{ number_format($account->current_balance, 2) }}</flux:heading>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800">
                        <flux:text class="text-sm">ฝากเงินรวม</flux:text>
                        <flux:heading size="lg" class="mt-1 text-green-600">{{ number_format($deposits, 2) }}</flux:heading>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800">
                        <flux:text class="text-sm">ถอนเงินรวม</flux:text>
                        <flux:heading size="lg" class="mt-1 text-red-600">{{ number_format($withdrawals, 2) }}</flux:heading>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <flux:heading size="md">Cash transactions</flux:heading>
                        <flux:text class="text-sm">{{ number_format($account->account_transactions_count) }} records</flux:text>
                    </div>
                    <div class="overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column align="center">Date</flux:table.column>
                                <flux:table.column>Type</flux:table.column>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Note</flux:table.column>
                                <flux:table.column align="center">Action</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($account->accountTransactions as $transaction)
                                    @php($signedAmount = $transaction->signedAmount())
                                    <flux:table.row :key="'account-transaction-'.$transaction->id">
                                        <flux:table.cell><span class="block text-center">{{ thai_date($transaction->transaction_date) }}</span></flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge :color="$transaction->type->badgeColor()" size="sm">{{ $transaction->type->label() }}</flux:badge>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="{{ $signedAmount >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($signedAmount, 2) }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $transaction->note ?: '-' }}</flux:table.cell>
                                        <flux:table.cell align="center">
                                            <div class="flex justify-center gap-2">
                                                <flux:button size="sm" icon="pencil" wire:click="editTransaction({{ $transaction->id }})">Edit</flux:button>
                                                <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDeleteTransaction({{ $transaction->id }})">Delete</flux:button>
                                            </div>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="5"><div class="py-6 text-center text-zinc-500">No cash transactions yet.</div></flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    @unless ($account->is_active)
                        <flux:button size="sm" icon="check-circle" wire:click="activate({{ $account->id }})">Set active</flux:button>
                        <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $account->id }})">Delete</flux:button>
                    @endunless
                </div>
            </flux:card>
        @endforeach
    </div>

    {{ $this->accounts->links() }}

    <flux:modal wire:model="showModal" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit trading account' : 'New trading account' }}</flux:heading>
            <flux:input wire:model="name" label="ชื่อบัญชี" required />
            <flux:input type="number" min="0" step="0.01" wire:model="initial_balance" label="เงินทุนเริ่มต้น" description="ยอดคงเหลือปัจจุบันจะคำนวณใหม่จากเงินทุนนี้และผลกำไร/ขาดทุนเดิม" required />
            <flux:switch wire:model="is_active" label="ใช้เป็นบัญชีหลักสำหรับรายการใหม่" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showTransactionModal" class="md:w-[32rem]">
        <form wire:submit="saveTransaction" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingTransactionId ? 'Edit cash transaction' : 'Deposit / Withdraw' }}</flux:heading>
                <flux:text>บันทึกรายการเงินเข้าออกของบัญชี <strong>{{ $transactionAccountLabel }}</strong></flux:text>
            </div>
            <flux:select wire:model="transaction_type" label="ประเภท">
                <flux:select.option value="deposit">ฝากเงิน</flux:select.option>
                <flux:select.option value="withdrawal">ถอนเงิน</flux:select.option>
            </flux:select>
            <flux:input type="number" min="0.01" step="0.01" wire:model="transaction_amount" label="จำนวนเงิน" required />
            @include('partials.thai-date-picker', ['field' => 'transaction_date', 'label' => 'วันที่ (พ.ศ.)'])
            <flux:textarea wire:model="transaction_note" label="หมายเหตุ" rows="3" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showTransactionModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save transaction</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteTransactionModal" class="md:w-[28rem]"><div class="space-y-6"><div><flux:heading size="lg">Delete cash transaction?</flux:heading><flux:text class="mt-2">ต้องการลบรายการ <strong>{{ $deletingTransactionLabel }}</strong> หรือไม่? ระบบจะคำนวณยอดคงเหลือใหม่อัตโนมัติ</flux:text></div><div class="flex justify-end gap-2"><flux:button wire:click="$set('showDeleteTransactionModal', false)">Cancel</flux:button><flux:button variant="danger" icon="trash" wire:click="deleteTransactionConfirmed">Delete</flux:button></div></div></flux:modal>

    <flux:modal wire:model="showDeleteModal" class="md:w-[28rem]"><div class="space-y-6"><div><flux:heading size="lg">Delete trading account?</flux:heading><flux:text class="mt-2">ต้องการลบบัญชี <strong>{{ $deletingLabel }}</strong> หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้</flux:text></div><div class="flex justify-end gap-2"><flux:button wire:click="$set('showDeleteModal', false)">Cancel</flux:button><flux:button variant="danger" icon="trash" wire:click="deleteConfirmed">Delete</flux:button></div></div></flux:modal>
</div>
