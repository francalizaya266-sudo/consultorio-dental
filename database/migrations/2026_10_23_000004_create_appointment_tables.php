<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            // Sesión del plan de tratamiento (la llave foránea se agrega con las tablas clínicas).
            $table->unsignedBigInteger('treatment_plan_item_id')->nullable()->index();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // Tiempo de esterilización reservado después de la cita.
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('booked_via', 20);
            $table->string('origin', 30)->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promo_code', 30)->nullable();
            $table->boolean('is_new_patient')->default(false);
            // El doctor la marcó con fecha fija: el paciente no la mueve solo.
            $table->boolean('fixed_date')->default(false);
            $table->boolean('from_verified_device')->default(false);
            $table->string('patient_note', 500)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_via', 20)->nullable();
            // El día anterior sin confirmar: recepción debe llamar.
            $table->timestamp('unconfirmed_flagged_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['doctor_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        // Línea de tiempo legible de cada cita (creada, confirmada, movida…).
        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('actor', 20); // patient | staff | system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('from_starts_at')->nullable();
            $table->dateTime('to_starts_at')->nullable();
            $table->string('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Cambio de horario que necesita aprobación (nivel 2).
        Schema::create('reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('requested_by', 20);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->foreignId('requested_treatment_id')->nullable()->constrained('treatments')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('approved_option_id')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('response_note', 500)->nullable();
            $table->timestamps();
        });

        // Hasta 3 horarios propuestos por el paciente.
        Schema::create('reschedule_request_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reschedule_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });

        // Enlaces personales que viajan en los mensajes de WhatsApp.
        // Solo se guarda el hash: el enlace se arma en el momento de enviar.
        Schema::create('magic_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('earliest_date')->nullable();
            $table->date('latest_date')->nullable();
            $table->string('preferred_period', 20)->default('any'); // any | morning | afternoon
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('waiting')->index();
            $table->timestamp('offered_at')->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('magic_links');
        Schema::dropIfExists('reschedule_request_options');
        Schema::dropIfExists('reschedule_requests');
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointments');
    }
};
