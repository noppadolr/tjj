<!DOCTYPE html>
<html lang="th">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Trading Journal" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="arrows-right-left" :href="route('trades.index')" :current="request()->routeIs('trades.*')" wire:navigate>Trades</flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('contracts.index')" :current="request()->routeIs('contracts.*')" wire:navigate>Contracts</flux:sidebar.item>
                    <flux:sidebar.item icon="wallet" :href="route('accounts.index')" :current="request()->routeIs('accounts.*')" wire:navigate>Trading Accounts</flux:sidebar.item>
                    <flux:sidebar.item icon="banknotes" :href="route('commissions.index')" :current="request()->routeIs('commissions.*')" wire:navigate>Commission</flux:sidebar.item>
                    <flux:sidebar.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>Reports</flux:sidebar.item>
                    <flux:sidebar.item icon="shield-check" :href="route('security.edit')" :current="request()->routeIs('security.edit')" wire:navigate>Security Settings</flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />
            <div class="space-y-3 border-t border-zinc-200 px-3 py-4 dark:border-zinc-700">
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ auth()->user()->name }}</div>
                    <div class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:button type="submit" icon="arrow-right-start-on-rectangle" class="w-full">Log out</flux:button>
                </form>
            </div>
            <div class="px-3 pb-3 text-xs text-zinc-500">Tfex Journal</div>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
