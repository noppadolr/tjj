<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();
            $table->string('contract', 50);
            $table->decimal('commission_per_contract', 10, 2)->default(0);
            $table->decimal('vat_percent', 5, 2)->default(7);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['contract', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rates');
    }
};
