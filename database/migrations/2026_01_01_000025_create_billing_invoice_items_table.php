<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('plan_tier', 50);
            $table->string('plan_name', 100);
            $table->integer('employee_count');
            $table->decimal('unit_price', 10, 2);       // monthly_fee_etb of the plan
            $table->decimal('subtotal', 12, 2);         // employee_count × unit_price
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_items');
    }
};
