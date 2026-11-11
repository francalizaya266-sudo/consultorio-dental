<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pendientes de recepción: lo que hay que acordarse de hacer un día, para que no dependa de un papel ni de quién esté de turno. */
    public function up(): void
    {
        Schema::create('desk_notes', function (Blueprint $table) {
            $table->id();
            $table->string('body', 300);
            $table->date('due_on')->index();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_notes');
    }
};
