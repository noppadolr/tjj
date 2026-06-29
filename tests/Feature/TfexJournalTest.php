<?php

use App\Models\AccountTransaction;
use App\Models\CommissionRate;
use App\Models\Contract;
use App\Models\EquitySnapshot;
use App\Models\Trade;
use App\Models\TradeCommission;
use App\Models\TradeExit;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    CommissionRate::query()->update(['effective_date' => '2020-01-01']);
});

it('has all nine trading journal tables', function () {
    $tables = [
        'trading_accounts', 'contracts', 'commission_rates', 'trades', 'trade_exits',
        'trade_commissions', 'equity_snapshots', 'trading_notes', 'trading_screenshots',
    ];

    expect(collect($tables)->every(fn ($table) => Schema::hasTable($table)))->toBeTrue();
});

it('renders every journal page', function (string $route) {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get($route)
        ->assertOk();
})->with(['/dashboard', '/trades', '/contracts', '/accounts', '/commissions', '/reports']);

it('renders account cards at full content width', function () {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/accounts')
        ->assertOk()
        ->assertSee('class="grid gap-4"', false)
        ->assertSee('text-center', false)
        ->assertSee('w-full space-y-5', false)
        ->assertDontSee('lg:grid-cols-2', false);
});

it('uses pagination on every page that has data tables', function (string $view, string $paginatedProperty) {
    $contents = file_get_contents(resource_path($view));

    expect($contents)
        ->toContain('BaseIndexComponent')
        ->toContain('getRowsQuery')
        ->toContain('->paginate(')
        ->toContain('$this->'.$paginatedProperty.'->links()');
})->with([
    ['views/pages/accounts/⚡index.blade.php', 'accounts'],
    ['views/pages/trades/⚡index.blade.php', 'trades'],
    ['views/pages/contracts/⚡index.blade.php', 'contracts'],
    ['views/pages/commissions/⚡index.blade.php', 'rates'],
    ['views/pages/reports/⚡index.blade.php', 'exits'],
]);

it('moves to the previous page after deleting the only row on the last page', function () {
    CommissionRate::query()->delete();

    $contract = Contract::firstOrCreate(
        ['symbol' => 'PGT'],
        ['name' => 'Pagination Test', 'multiplier' => 200, 'is_active' => true],
    );

    foreach (range(1, 11) as $day) {
        CommissionRate::create([
            'contract_id' => $contract->id,
            'broker_name' => 'Broker '.$day,
            'contract' => 'PGT',
            'commission_per_contract' => 10,
            'vat_percent' => 7,
            'effective_date' => now()->startOfYear()->addDays($day - 1)->toDateString(),
            'is_active' => true,
        ]);
    }

    $oldestRate = CommissionRate::oldest('effective_date')->firstOrFail();

    Livewire::test('pages::commissions.index')
        ->call('gotoPage', 2)
        ->call('confirmDelete', $oldestRate->id)
        ->call('deleteConfirmed')
        ->assertSet('paginators.page', 1)
        ->assertDontSee('No commission rates found.');
});

it('shows long and short trade counts with trading costs on the dashboard', function () {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Long Trades')
        ->assertSee('Short Trades')
        ->assertSee('Profit Trades (% of Total Short)')
        ->assertSee('Loss Trades (% of Total Long)')
        ->assertSee('Total Commission')
        ->assertSee('Total VAT')
        ->assertSee('Total Cost');
});

it('shows total commission vat and cost values on the dashboard', function () {
    Livewire::test('pages::trades.index')
        ->set('trade_date', '2026-06-20')
        ->set('contract', 'S50')
        ->set('position_type', 'long')
        ->set('total_contracts', 2)
        ->set('entry_price', 1000)
        ->set('entry_date', '2026-06-20')
        ->call('save');

    $trade = Trade::firstOrFail();

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 1)
        ->call('closeTrade')
        ->assertHasNoErrors();

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Total Commission')
        ->assertSee('160.00')
        ->assertSee('Total VAT')
        ->assertSee('11.20')
        ->assertSee('Total Cost')
        ->assertSee('171.20');
});

it('shows saved trade exit cost even when the commission relation is missing', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create([
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-20',
        'exit_price' => 1010,
        'exit_contracts' => 1,
        'gross_profit' => 2000,
        'commission' => 80,
        'vat' => 5.60,
        'total_cost' => 85.60,
        'net_profit' => 1914.40,
    ]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/trades')
        ->assertOk()
        ->assertSee('Close Cost 85.60');
});

it('calculates trade exit cost from commission rate when saved cost is zero', function () {
    $account = TradingAccount::first();
    $contract = Contract::firstOrCreate(
        ['symbol' => 'ZCO'],
        ['name' => 'Zero Cost Test', 'multiplier' => 200, 'is_active' => true],
    );

    CommissionRate::create([
        'contract_id' => $contract->id,
        'broker_name' => 'Zero Cost Broker',
        'contract' => 'ZCO',
        'commission_per_contract' => 80,
        'vat_percent' => 7,
        'effective_date' => '2026-06-01',
        'is_active' => true,
    ]);

    $trade = $account->trades()->create([
        'contract_id' => $contract->id,
        'trade_date' => '2026-06-20',
        'contract' => 'ZCO',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-20',
        'exit_price' => 1010,
        'exit_contracts' => 1,
        'gross_profit' => 2000,
        'commission' => 0,
        'vat' => 0,
        'total_cost' => 0,
        'net_profit' => 2000,
    ]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/trades')
        ->assertOk()
        ->assertSee('Close Cost 85.60');
});

it('shows the opening cost on the trades table', function () {
    $account = TradingAccount::first();
    $contract = Contract::where('symbol', 'S50')->first();

    $account->trades()->create([
        'contract_id' => $contract->id,
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 2,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'open',
    ]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/trades')
        ->assertOk()
        ->assertSee('Open Commission')
        ->assertSee('Open VAT')
        ->assertSee('Open Cost')
        ->assertSee('160.00')
        ->assertSee('11.20')
        ->assertSee('171.20');
});

it('shows the close cost on the trades table', function () {
    Livewire::test('pages::trades.index')
        ->set('trade_date', '2026-06-20')
        ->set('contract', 'S50')
        ->set('position_type', 'long')
        ->set('total_contracts', 2)
        ->set('entry_price', 1000)
        ->set('entry_date', '2026-06-20')
        ->call('save');

    $trade = Trade::firstOrFail();

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 1)
        ->call('closeTrade')
        ->assertHasNoErrors();

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/trades')
        ->assertOk()
        ->assertSee('Open Cost')
        ->assertSee('Close Cost')
        ->assertSee('Close Commission')
        ->assertSee('Close VAT')
        ->assertSee('Gross 2,000.00')
        ->assertSee('Net 1,828.80')
        ->assertSee('171.20')
        ->assertSee('85.60');
});

it('calculates costs for a dated S50 contract before the first effective rate date', function () {
    CommissionRate::where('contract', 'S50')->update(['effective_date' => '2026-06-29']);

    $account = TradingAccount::first();
    $contract = Contract::firstOrCreate(
        ['symbol' => 'S50M26'],
        ['name' => 'SET50 Futures Jun 2026', 'multiplier' => 200, 'is_active' => true],
    );

    $trade = $account->trades()->create([
        'contract_id' => $contract->id,
        'trade_date' => '2026-06-19',
        'contract' => 'S50M26',
        'position_type' => 'short',
        'total_contracts' => 1,
        'entry_price' => 1030.70,
        'entry_date' => '2026-06-19',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-19',
        'exit_price' => 1020,
        'exit_contracts' => 1,
        'gross_profit' => 2140,
        'commission' => 0,
        'vat' => 0,
        'total_cost' => 0,
        'net_profit' => 2140,
    ]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/trades')
        ->assertOk()
        ->assertSee('19/06/2569')
        ->assertSee('S50M26')
        ->assertSee('Short')
        ->assertSee('1,030.70')
        ->assertSee('Open Commission')
        ->assertSee('Open VAT')
        ->assertSee('Open Cost')
        ->assertSee('Close Commission')
        ->assertSee('Close VAT')
        ->assertSee('Close Cost')
        ->assertSee('80.00')
        ->assertSee('5.60')
        ->assertSee('85.60')
        ->assertDontSee('Cost 0.00');
});

it('shows the current active account balance on the dashboard', function () {
    $account = TradingAccount::where('is_active', true)->firstOrFail();
    $account->update(['current_balance' => 123456.78]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Current Balance')
        ->assertSee('123,456.78');
});

it('requires login before accessing the journal', function (string $route) {
    $this->get($route)->assertRedirect('/login');
})->with(['/dashboard', '/trades', '/contracts', '/accounts', '/commissions', '/reports']);

it('seeds the admin login', function () {
    $admin = User::where('email', 'admin@example.com')->firstOrFail();

    expect($admin->name)->toBe('admin')
        ->and(Hash::check('111', $admin->password))->toBeTrue();
});

it('allows the verified admin to manage passkeys', function () {
    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/settings/security')
        ->assertOk()
        ->assertSee('Passkeys');
});

it('stores and searches broker names on commission rates', function () {
    Livewire::test('pages::commissions.index')
        ->call('create')
        ->set('broker_name', 'KGI Securities')
        ->set('contract', 'gold')
        ->set('commission_per_contract', 50)
        ->set('vat_percent', 7)
        ->set('effective_date', '2026-06-20')
        ->call('save')
        ->assertHasNoErrors();

    expect(CommissionRate::where('contract', 'GOLD')->first()->broker_name)->toBe('KGI Securities');

    Livewire::test('pages::commissions.index')
        ->set('search', 'KGI')
        ->assertSee('KGI Securities')
        ->assertSee('GOLD');
});

it('creates and partially closes a long trade with the correct account balance', function () {
    Livewire::test('pages::trades.index')
        ->set('trade_date', '2026-06-20')->set('contract', 'S50')->set('position_type', 'long')
        ->set('total_contracts', 2)->set('entry_price', 1000)->set('entry_date', '2026-06-20')->call('save');

    $trade = Trade::firstOrFail();
    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)->set('exit_date', '2026-06-20')->set('exit_price', 1010)
        ->set('exit_contracts', 1)->call('closeTrade')->assertHasNoErrors();

    expect(TradeExit::first()->gross_profit)->toBe('2000.00')
        ->and(TradeCommission::first()->commission)->toBe('160.00')
        ->and(TradeCommission::first()->vat)->toBe('11.20')
        ->and(TradeExit::first()->net_profit)->toBe('1828.80')
        ->and($trade->fresh()->status->value)->toBe('partial')
        ->and($trade->fresh()->remainingContracts())->toBe(1)
        ->and(TradingAccount::first()->current_balance)->toBe('101828.80')
        ->and(EquitySnapshot::count())->toBe(1);
});

it('calculates short profit in the correct direction', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create(['trade_date' => '2026-06-20', 'contract' => 'S50', 'position_type' => 'short', 'total_contracts' => 1, 'entry_price' => 1000, 'entry_date' => '2026-06-20', 'status' => 'open']);

    Livewire::test('pages::trades.index')->call('openClose', $trade->id)->set('exit_date', '2026-06-20')->set('exit_price', 990)->set('exit_contracts', 1)->call('closeTrade')->assertHasNoErrors();

    expect(TradeExit::first()->gross_profit)->toBe('2000.00')->and($trade->fresh()->status->value)->toBe('closed');
});

it('uses the underlying commission rate when closing a dated contract symbol', function () {
    $account = TradingAccount::first();
    $contract = Contract::create(['symbol' => 'S50M26', 'name' => 'SET50 Futures Jun 2026', 'multiplier' => 200, 'is_active' => true]);
    $trade = $account->trades()->create([
        'contract_id' => $contract->id,
        'trade_date' => '2026-06-20',
        'contract' => 'S50M26',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'open',
    ]);

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 1)
        ->call('closeTrade')
        ->assertHasNoErrors();

    $exit = TradeExit::firstOrFail();

    expect($exit->tradeCommission->commission)->toBe('160.00')
        ->and($exit->tradeCommission->vat)->toBe('11.20')
        ->and($exit->commission)->toBe('160.00')
        ->and($exit->vat)->toBe('11.20');

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/reports')
        ->assertOk()
        ->assertSee('Trade date')
        ->assertSee('20/06/2569')
        ->assertSee('S50M26')
        ->assertSee('160.00')
        ->assertSee('11.20');
});

it('shows legacy exit commission and vat values on reports', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create([
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-20',
        'exit_price' => 1010,
        'exit_contracts' => 1,
        'gross_profit' => 2000,
        'commission' => 80,
        'vat' => 5.60,
        'total_cost' => 85.60,
        'net_profit' => 1914.40,
    ]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/reports')
        ->assertOk()
        ->assertSee('160.00')
        ->assertSee('11.20')
        ->assertSee('171.20');
});

it('calculates displayed net profit from gross minus total cost on reports and dashboard', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create([
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-20',
        'exit_price' => 1010,
        'exit_contracts' => 1,
        'gross_profit' => 2000,
        'commission' => 80,
        'vat' => 5.60,
        'total_cost' => 85.60,
        'net_profit' => 2000,
    ]);

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/reports')
        ->assertOk()
        ->assertSee('Gross P/L')
        ->assertSee('2,000.00')
        ->assertSee('Net P/L')
        ->assertSee('1,828.80');

    $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Total Net Profit')
        ->assertSee('1,828.80');
});

it('exports reports as an excel file', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create([
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-20',
        'exit_price' => 1010,
        'exit_contracts' => 1,
        'gross_profit' => 2000,
        'commission' => 80,
        'vat' => 5.60,
        'total_cost' => 85.60,
        'net_profit' => 1914.40,
    ]);

    $response = $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/reports/export/xlsx?contract=S50')
        ->assertOk()
        ->assertDownload();

    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet');
});

it('exports reports as a pdf file', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create([
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 1,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'closed',
    ]);

    $trade->exits()->create([
        'exit_date' => '2026-06-20',
        'exit_price' => 1010,
        'exit_contracts' => 1,
        'gross_profit' => 2000,
        'commission' => 80,
        'vat' => 5.60,
        'total_cost' => 85.60,
        'net_profit' => 1914.40,
    ]);

    $response = $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
        ->get('/reports/export/pdf?contract=S50')
        ->assertOk()
        ->assertDownload();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF-1.4');
});

it('saves starting capital and rebases the account equity history', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create(['trade_date' => '2026-06-20', 'contract' => 'S50', 'position_type' => 'long', 'total_contracts' => 1, 'entry_price' => 1000, 'entry_date' => '2026-06-20', 'status' => 'open']);

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 1)
        ->call('closeTrade');

    Livewire::test('pages::accounts.index')
        ->call('edit', $account->id)
        ->set('initial_balance', 200000)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->initial_balance)->toBe('200000.00')
        ->and($account->fresh()->current_balance)->toBe('201828.80')
        ->and(EquitySnapshot::first()->balance_before)->toBe('200000.00')
        ->and(EquitySnapshot::first()->balance_after)->toBe('201828.80');
});

it('records account deposits and withdrawals in the current balance', function () {
    $account = TradingAccount::first();

    Livewire::test('pages::accounts.index')
        ->call('openTransaction', $account->id, 'deposit')
        ->set('transaction_amount', 5000)
        ->set('transaction_date', '2026-06-20')
        ->set('transaction_note', 'เติมเงินเข้าบัญชี')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    Livewire::test('pages::accounts.index')
        ->call('openTransaction', $account->id, 'withdrawal')
        ->set('transaction_amount', 20000)
        ->set('transaction_date', '2026-06-21')
        ->set('transaction_note', 'ถอนเงินออก')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect(AccountTransaction::count())->toBe(2)
        ->and(EquitySnapshot::count())->toBe(2)
        ->and(EquitySnapshot::orderBy('snapshot_date')->orderBy('id')->first()->net_profit)->toBe('5000.00')
        ->and(EquitySnapshot::orderByDesc('snapshot_date')->orderByDesc('id')->first()->net_profit)->toBe('-20000.00')
        ->and($account->fresh()->current_balance)->toBe('85000.00');
});

it('prevents withdrawing more than the current account balance', function () {
    $account = TradingAccount::first();

    Livewire::test('pages::accounts.index')
        ->call('openTransaction', $account->id, 'withdrawal')
        ->set('transaction_amount', 100000.01)
        ->set('transaction_date', '2026-06-20')
        ->call('saveTransaction')
        ->assertHasErrors(['transaction_amount']);

    expect(AccountTransaction::count())->toBe(0)
        ->and(EquitySnapshot::count())->toBe(0)
        ->and($account->fresh()->current_balance)->toBe('100000.00');
});

it('updates and deletes account cash transactions with rebased balances', function () {
    $account = TradingAccount::first();

    Livewire::test('pages::accounts.index')
        ->call('openTransaction', $account->id, 'withdrawal')
        ->set('transaction_amount', 10000)
        ->set('transaction_date', '2026-06-20')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $transaction = AccountTransaction::firstOrFail();

    Livewire::test('pages::accounts.index')
        ->call('editTransaction', $transaction->id)
        ->assertSet('editingTransactionId', $transaction->id)
        ->set('transaction_amount', 15000)
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect($transaction->fresh()->amount)->toBe('15000.00')
        ->and(EquitySnapshot::first()->net_profit)->toBe('-15000.00')
        ->and(EquitySnapshot::first()->balance_after)->toBe('85000.00')
        ->and($account->fresh()->current_balance)->toBe('85000.00');

    Livewire::test('pages::accounts.index')
        ->call('confirmDeleteTransaction', $transaction->id)
        ->assertSet('showDeleteTransactionModal', true)
        ->call('deleteTransactionConfirmed')
        ->assertHasNoErrors();

    expect(AccountTransaction::count())->toBe(0)
        ->and(EquitySnapshot::count())->toBe(0)
        ->and($account->fresh()->current_balance)->toBe('100000.00');
});

it('rebases later equity snapshots when a historical withdrawal is added', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create(['trade_date' => '2026-06-21', 'contract' => 'S50', 'position_type' => 'long', 'total_contracts' => 1, 'entry_price' => 1000, 'entry_date' => '2026-06-21', 'status' => 'open']);

    Livewire::test('pages::accounts.index')
        ->call('openTransaction', $account->id, 'deposit')
        ->set('transaction_amount', 1000)
        ->set('transaction_date', '2026-06-21')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-22')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 1)
        ->call('closeTrade')
        ->assertHasNoErrors();

    Livewire::test('pages::accounts.index')
        ->call('openTransaction', $account->id, 'withdrawal')
        ->set('transaction_amount', 10000)
        ->set('transaction_date', '2026-06-20')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $snapshots = EquitySnapshot::orderBy('snapshot_date')->orderBy('id')->get();

    expect($snapshots[0]->net_profit)->toBe('-10000.00')
        ->and($snapshots[0]->balance_before)->toBe('100000.00')
        ->and($snapshots[0]->balance_after)->toBe('90000.00')
        ->and($snapshots[1]->net_profit)->toBe('1000.00')
        ->and($snapshots[1]->balance_before)->toBe('90000.00')
        ->and($snapshots[1]->balance_after)->toBe('91000.00')
        ->and($snapshots[2]->net_profit)->toBe('1828.80')
        ->and($snapshots[2]->balance_before)->toBe('91000.00')
        ->and($snapshots[2]->balance_after)->toBe('92828.80')
        ->and($account->fresh()->current_balance)->toBe('92828.80');
});

it('accepts a Buddhist Era trade date and stores it as Gregorian', function () {
    Livewire::test('pages::trades.index')
        ->set('trade_date', '20/06/2569')
        ->set('contract', 'S50')
        ->set('position_type', 'long')
        ->set('total_contracts', 1)
        ->set('entry_price', 1000)
        ->set('entry_date', '20/06/2569')
        ->call('save')
        ->assertHasNoErrors();

    expect(Trade::first()->trade_date->toDateString())->toBe('2026-06-20')
        ->and(Trade::first()->entry_date->toDateString())->toBe('2026-06-20');
});

it('partially closes two of ten contracts and leaves eight open', function () {
    $account = TradingAccount::first();
    $trade = $account->trades()->create([
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 10,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'open',
    ]);

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 2)
        ->call('closeTrade')
        ->assertHasNoErrors();

    expect($trade->fresh()->closedContracts())->toBe(2)
        ->and($trade->fresh()->remainingContracts())->toBe(8)
        ->and($trade->fresh()->status->value)->toBe('partial')
        ->and(TradeCommission::first()->commission)->toBe('320.00');
});

it('creates a contract and selects it for a new trade', function () {
    Livewire::test('pages::contracts.index')
        ->call('create')
        ->set('symbol', 'gold')
        ->set('name', 'Gold Futures')
        ->set('multiplier', 100)
        ->call('save')
        ->assertHasNoErrors();

    $contract = Contract::where('symbol', 'GOLD')->firstOrFail();

    Livewire::test('pages::trades.index')
        ->set('trade_date', '20/06/2569')
        ->set('contract', 'GOLD')
        ->set('position_type', 'long')
        ->set('total_contracts', 1)
        ->set('entry_price', 3000)
        ->set('entry_date', '2026-06-20')
        ->call('save')
        ->assertHasNoErrors();

    expect(Trade::first()->contract_id)->toBe($contract->id)
        ->and(Trade::first()->contract)->toBe('GOLD');
});

it('corrects a saved exit price and rebases all later equity balances', function () {
    $account = TradingAccount::first();
    $contract = Contract::where('symbol', 'S50')->first();
    $trade = $account->trades()->create([
        'contract_id' => $contract->id,
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 2,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'open',
    ]);

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 1)
        ->call('closeTrade');

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-21')
        ->set('exit_price', 1005)
        ->set('exit_contracts', 1)
        ->call('closeTrade');

    $firstExit = TradeExit::oldest('id')->first();

    Livewire::test('pages::trades.index')
        ->call('editExit', $firstExit->id)
        ->set('editingExitPrice', 1020)
        ->call('updateExitPrice')
        ->assertHasNoErrors();

    $snapshots = EquitySnapshot::orderBy('snapshot_date')->get();

    expect($firstExit->fresh()->gross_profit)->toBe('4000.00')
        ->and($firstExit->fresh()->net_profit)->toBe('3828.80')
        ->and($snapshots[0]->trade_exit_id)->toBe($firstExit->id)
        ->and($snapshots[0]->balance_after)->toBe('103828.80')
        ->and($snapshots[1]->balance_before)->toBe('103828.80')
        ->and($snapshots[1]->balance_after)->toBe('104657.60')
        ->and($account->fresh()->current_balance)->toBe('104657.60');
});

it('cancels a partial close and restores contracts and account balance', function () {
    $account = TradingAccount::first();
    $contract = Contract::where('symbol', 'S50')->first();
    $trade = $account->trades()->create([
        'contract_id' => $contract->id,
        'trade_date' => '2026-06-20',
        'contract' => 'S50',
        'position_type' => 'long',
        'total_contracts' => 10,
        'entry_price' => 1000,
        'entry_date' => '2026-06-20',
        'status' => 'open',
    ]);

    Livewire::test('pages::trades.index')
        ->call('openClose', $trade->id)
        ->set('exit_date', '2026-06-20')
        ->set('exit_price', 1010)
        ->set('exit_contracts', 2)
        ->call('closeTrade');

    $exit = TradeExit::firstOrFail();

    Livewire::test('pages::trades.index')
        ->call('confirmCancelExit', $exit->id)
        ->assertSet('showCancelExitModal', true)
        ->call('cancelExit')
        ->assertHasNoErrors();

    expect($trade->fresh()->closedContracts())->toBe(0)
        ->and($trade->fresh()->remainingContracts())->toBe(10)
        ->and($trade->fresh()->status->value)->toBe('open')
        ->and($account->fresh()->current_balance)->toBe('100000.00')
        ->and(EquitySnapshot::count())->toBe(0)
        ->and(TradeCommission::count())->toBe(0)
        ->and(TradeExit::count())->toBe(0)
        ->and(TradeExit::withTrashed()->count())->toBe(1);
});
