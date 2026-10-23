<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price_from', 10, 2)->nullable();
            $table->boolean('show_price')->default(true);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            // Tiempo de limpieza y esterilización; null = valor general del consultorio.
            $table->unsignedSmallInteger('buffer_minutes')->nullable();
            $table->string('sessions_label', 50)->nullable();
            $table->boolean('is_evaluation')->default(false);
            // El paciente puede reservarlo solo en la web como primera cita.
            $table->boolean('online_bookable')->default(false);
            $table->boolean('is_public')->default(true);
            $table->string('image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('specialty')->nullable();
            // Colegio Odontológico del Perú y Registro Nacional de Especialista.
            $table->string('cop_number', 20)->nullable();
            $table->string('rne_number', 20)->nullable();
            $table->text('bio')->nullable();
            $table->text('education')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('agenda_color', 7)->default('#0ea5e9');
            $table->boolean('is_public')->default(true);
            $table->boolean('accepts_online_booking')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Qué tratamientos hace cada doctor y, si quiere, con su propio tiempo.
        Schema::create('doctor_treatment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->timestamps();
            $table->unique(['doctor_id', 'treatment_id']);
        });

        // Horario semanal de atención (puede haber varios tramos por día).
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO: 1 = lunes … 7 = domingo
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['doctor_id', 'weekday']);
        });

        // Horarios bloqueados: almuerzo, cursos, vacaciones. Sin doctor = todo el consultorio.
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('other');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['doctor_id', 'starts_at']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('discount_type', 10)->default('none');
            $table->decimal('discount_value', 10, 2)->nullable();
            // Se publica y se retira sola según estas fechas.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('promo_code', 30)->nullable()->unique();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->boolean('show_on_home')->default(true);
            $table->text('whatsapp_text')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('schedule_blocks');
        Schema::dropIfExists('doctor_schedules');
        Schema::dropIfExists('doctor_treatment');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('treatments');
    }
};
