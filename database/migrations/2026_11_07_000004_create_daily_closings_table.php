<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El cierre de caja de cada día: el arqueo del efectivo y quién lo cerró. Cerrado, el día ya no se cambia sin la administradora. */
    public function up(): void
    {
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('opening_cash', 10, 2)->default(0);
            $table->decimal('expected_cash', 10, 2);
            $table->decimal('counted_cash', 10, 2);
            $table->decimal('collected', 10, 2);
            $table->decimal('pending', 10, 2)->default(0);
            $table->string('notes')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
    }
};
