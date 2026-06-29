@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Tfex Journal" {{ $attributes }} class="mt-4">
        <x-slot name="logo" class="flex aspect-square size-20 items-center justify-center overflow-hidden rounded-md ">
            <x-app-logo-icon class="size-20" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Tfex Journal" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-20 items-center justify-center overflow-hidden rounded-md ">
            <x-app-logo-icon class="size-20" />
        </x-slot>
    </flux:brand>
@endif
