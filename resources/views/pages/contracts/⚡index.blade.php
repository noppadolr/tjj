<?php

use App\Livewire\BaseIndexComponent;
use App\Models\Contract;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

new class extends BaseIndexComponent {
    public string $search = '';

    public ?int $editingId = null;

    public bool $showModal = false;
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingLabel = '';

    public string $symbol = '';

    public ?string $name = null;

    public float|string $multiplier = 200;

    public bool $is_active = true;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function contracts()
    {
        return $this->getRowsQuery()->paginate($this->perPage);
    }

    protected function getRowsQuery(): Builder
    {
        return Contract::query()
            ->withCount(['trades', 'commissionRates'])
            ->when($this->search, fn (Builder $query) => $query->where(function (Builder $query) {
                $query->where('symbol', 'like', '%'.$this->search.'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%');
            }))
            ->orderByDesc('is_active')
            ->orderBy('symbol');
    }

    public function create(): void
    {
        $this->reset(['editingId', 'symbol', 'name']);
        $this->multiplier = 200;
        $this->is_active = true;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $contract = Contract::findOrFail($id);
        $this->editingId = $contract->id;
        $this->symbol = $contract->symbol;
        $this->name = $contract->name;
        $this->multiplier = $contract->multiplier;
        $this->is_active = $contract->is_active;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->symbol = strtoupper(trim($this->symbol));

        $data = $this->validate([
            'symbol' => ['required', 'string', 'max:50', Rule::unique('contracts', 'symbol')->ignore($this->editingId)],
            'name' => 'nullable|string|max:255',
            'multiplier' => 'required|numeric|min:0.01|max:99999999.99',
            'is_active' => 'boolean',
        ]);

        $data['name'] = filled($data['name']) ? trim($data['name']) : null;

        Contract::updateOrCreate(['id' => $this->editingId], $data);

        $wasEditing = filled($this->editingId);
        $this->showModal = false;
        unset($this->contracts);
        Flux::toast($wasEditing ? 'Contract updated.' : 'Contract created.', variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $contract = Contract::findOrFail($id);
        $this->deletingId = $contract->id;
        $this->deletingLabel = $contract->symbol;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        $this->showDeleteModal = false;
        $contract = Contract::withCount(['trades', 'commissionRates'])->findOrFail($this->deletingId);

        if ($contract->trades_count > 0 || $contract->commission_rates_count > 0) {
            Flux::toast('A contract in use cannot be deleted. Set it inactive instead.', variant: 'warning');

            return;
        }

        $contract->delete();
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->adjustPageAfterDelete();
        unset($this->contracts);
        Flux::toast('Contract deleted.', variant: 'success');
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Contracts</flux:heading>
            <flux:text>จัดการสัญญาที่สามารถเลือกใช้ในรายการเทรด</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">New contract</flux:button>
    </div>

    <flux:card class="space-y-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ค้นหาด้วย Symbol หรือชื่อสัญญา เช่น S50 หรือ SET50" />
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Symbol</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Multiplier</flux:table.column>
                    <flux:table.column>Trades</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->contracts as $contract)
                        <flux:table.row :key="$contract->id">
                            <flux:table.cell variant="strong">{{ $contract->symbol }}</flux:table.cell>
                            <flux:table.cell>{{ $contract->name ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($contract->multiplier, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($contract->trades_count) }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$contract->is_active ? 'green' : 'zinc'" size="sm">{{ $contract->is_active ? 'Active' : 'Inactive' }}</flux:badge></flux:table.cell>
                            <flux:table.cell><div class="flex justify-end gap-2"><flux:button size="sm" icon="pencil" wire:click="edit({{ $contract->id }})">Edit</flux:button><flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $contract->id }})">Delete</flux:button></div></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="6"><div class="py-8 text-center text-zinc-500">No contracts found.</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
        {{ $this->contracts->links() }}
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit contract' : 'New contract' }}</flux:heading>
            <flux:input wire:model="symbol" label="Symbol" placeholder="S50" required />
            <flux:input wire:model="name" label="Contract name" placeholder="SET50 Index Futures" />
            <flux:input type="number" min="0.01" step="0.01" wire:model="multiplier" label="Multiplier" required />
            <flux:switch wire:model="is_active" label="Active and available for new trades" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeleteModal" class="md:w-[28rem]"><div class="space-y-6"><div><flux:heading size="lg">Delete contract?</flux:heading><flux:text class="mt-2">ต้องการลบสัญญา <strong>{{ $deletingLabel }}</strong> หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้</flux:text></div><div class="flex justify-end gap-2"><flux:button wire:click="$set('showDeleteModal', false)">Cancel</flux:button><flux:button variant="danger" icon="trash" wire:click="deleteConfirmed">Delete</flux:button></div></div></flux:modal>
</div>
