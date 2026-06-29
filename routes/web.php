<?php

use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard.index')->name('dashboard');
    Route::livewire('/trades', 'pages::trades.index')->name('trades.index');
    Route::livewire('/contracts', 'pages::contracts.index')->name('contracts.index');
    Route::livewire('/accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('/commissions', 'pages::commissions.index')->name('commissions.index');
    Route::get('/reports/export/{format}', ReportExportController::class)->whereIn('format', ['pdf', 'xlsx'])->name('reports.export');
    Route::livewire('/reports', 'pages::reports.index')->name('reports.index');
});

require __DIR__.'/settings.php';
