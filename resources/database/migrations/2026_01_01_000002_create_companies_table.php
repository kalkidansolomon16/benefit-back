<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('industry', ['banking','ngo','government','international_school','hospital','real_estate','telecom','airline','insurance','tech','embassy','other']);
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->unique();
            $table->string('contact_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->default('Addis Ababa');
            $table->enum('tier', ['platinum','basic_plus','basic'])->default('basic');
            $table->integer('employee_count')->default(0);
            $table->string('logo_path')->nullable();
            $table->string('tin_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('companies'); }
};
