<?php

use App\Livewire\BaseIndexComponent;
use App\Models\CommissionRate;
use App\Models\Contract;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;

new class extends BaseIndexComponent {
    public string $search = '';
    public ?int $editingId = null;
    public bool $showModal = false;
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingLabel = '';
    public string $broker_name = '';
    public string $contract = '';
    public float|string $commission_per_contract = 0;
    public float|string $vat_percent = 7;
    public string $effective_date = '';
    public bool $is_active = true;

    public function mount(): void { $this->effective_date = today()->toDateString(); }
    public function updatedSearch(): void { $this->resetPage(); }

    #[Computed]
    public function rates()
    {
        return $this->getRowsQuery()->paginate($this->perPage);
    }

    protected function getRowsQuery(): Builder
    {
        return CommissionRate::query()
            ->where(function (Builder $query) {
                $query->where('contract', 'like', '%'.$this->search.'%')
                    ->orWhere('broker_name', 'like', '%'.$this->search.'%');
            })
            ->latest('effective_date');
    }

    public function create(): void
    {
        $this->reset(['editingId','broker_name','contract','showModal']);
        $this->commission_per_contract = 0; $this->vat_percent = 7; $this->effective_date = today()->toDateString(); $this->is_active = true; $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $rate = CommissionRate::findOrFail($id);
        $this->editingId = $rate->id; $this->broker_name = $rate->broker_name ?? ''; $this->contract = $rate->contract; $this->commission_per_contract = $rate->commission_per_contract;
        $this->vat_percent = $rate->vat_percent; $this->effective_date = $rate->effective_date->toDateString(); $this->is_active = $rate->is_active; $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'broker_name'=>'nullable|string|max:255',
            'contract'=>'required|string|max:50', 'commission_per_contract'=>'required|numeric|min:0', 'vat_percent'=>'required|numeric|min:0|max:100',
            'effective_date'=>'required|date', 'is_active'=>'boolean',
        ]);
        $data['broker_name'] = filled($data['broker_name'] ?? '') ? trim($data['broker_name']) : null;
        $data['contract'] = strtoupper(trim($data['contract']));
        $contract = Contract::firstOrCreate(
            ['symbol' => $data['contract']],
            ['name' => $data['contract'], 'multiplier' => 200, 'is_active' => true],
        );
        $data['contract_id'] = $contract->id;
        CommissionRate::updateOrCreate(['id'=>$this->editingId], $data);
        $this->showModal = false; unset($this->rates); Flux::toast($this->editingId ? 'Commission rate updated.' : 'Commission rate created.', variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $rate = CommissionRate::findOrFail($id);
        $this->deletingId = $rate->id;
        $this->deletingLabel = $rate->contract;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        CommissionRate::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false; $this->deletingId = null; $this->adjustPageAfterDelete(); unset($this->rates); Flux::toast('Commission rate deleted.', variant: 'success');
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div><flux:heading size="xl">Commission Rates</flux:heading><flux:text>Manage costs by contract and effective date.</flux:text></div>
        <flux:button variant="primary" icon="plus" wire:click="create">New rate</flux:button>
    </div>
    <flux:card class="space-y-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ค้นหาด้วย Broker หรือ Symbol สัญญา เช่น BLS หรือ S50" />
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns><flux:table.column>Broker</flux:table.column><flux:table.column>Contract</flux:table.column><flux:table.column>Commission</flux:table.column><flux:table.column>VAT</flux:table.column><flux:table.column>Effective date</flux:table.column><flux:table.column>Status</flux:table.column><flux:table.column></flux:table.column></flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->rates as $rate)
                        <flux:table.row :key="$rate->id">
                            <flux:table.cell>{{ $rate->broker_name ?: '-' }}</flux:table.cell><flux:table.cell variant="strong">{{ $rate->contract }}</flux:table.cell><flux:table.cell>{{ number_format($rate->commission_per_contract, 2) }}</flux:table.cell><flux:table.cell>{{ number_format($rate->vat_percent, 2) }}%</flux:table.cell><flux:table.cell>{{ thai_date($rate->effective_date) }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$rate->is_active ? 'green' : 'zinc'" size="sm">{{ $rate->is_active ? 'Active' : 'Inactive' }}</flux:badge></flux:table.cell>
                            <flux:table.cell><div class="flex justify-end gap-2"><flux:button size="sm" icon="pencil" wire:click="edit({{ $rate->id }})">Edit</flux:button><flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $rate->id }})">Delete</flux:button></div></flux:table.cell>
                        </flux:table.row>
                    @empty <flux:table.row><flux:table.cell colspan="7"><div class="py-8 text-center text-zinc-500">No commission rates found.</div></flux:table.cell></flux:table.row> @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
        {{ $this->rates->links() }}
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit commission rate' : 'New commission rate' }}</flux:heading>
            <flux:input wire:model="broker_name" label="Broker name" placeholder="เช่น BLS, KGI, Finansia" />
            <flux:input wire:model="contract" label="Contract" required />
            <div class="grid grid-cols-2 gap-4"><flux:input type="number" step="0.01" wire:model="commission_per_contract" label="Commission / contract" required /><flux:input type="number" step="0.01" wire:model="vat_percent" label="VAT percent" required /></div>
            <flux:input type="date" wire:model="effective_date" label="Effective date (AD)" required />
            <flux:switch wire:model="is_active" label="Active" />
            <div class="flex justify-end gap-2"><flux:button type="button" wire:click="$set('showModal', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showDeleteModal" class="md:w-[28rem]"><div class="space-y-6"><div><flux:heading size="lg">Delete commission rate?</flux:heading><flux:text class="mt-2">ต้องการลบอัตราค่าคอมมิชชันของ <strong>{{ $deletingLabel }}</strong> หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้</flux:text></div><div class="flex justify-end gap-2"><flux:button wire:click="$set('showDeleteModal', false)">Cancel</flux:button><flux:button variant="danger" icon="trash" wire:click="deleteConfirmed">Delete</flux:button></div></div></flux:modal>
</div>
