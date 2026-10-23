<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Antecedentes médicos: una ficha vigente por paciente (los cambios quedan en auditoría).
        Schema::create('medical_backgrounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('conditions')->nullable(); // diabetes, hipertensión, cardiopatía…
            $table->text('allergies')->nullable();
            $table->text('medications')->nullable();
            $table->text('surgeries')->nullable();
            $table->boolean('is_pregnant')->nullable();
            $table->unsignedTinyInteger('pregnancy_weeks')->nullable();
            $table->boolean('is_breastfeeding')->nullable();
            $table->boolean('smoker')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Cada atención: motivo, examen, diagnóstico, evolución e indicaciones.
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('attended_at');
            $table->text('chief_complaint')->nullable();
            $table->text('current_illness')->nullable();
            $table->text('clinical_exam')->nullable();
            $table->json('vital_signs')->nullable();
            $table->text('diagnosis_notes')->nullable();
            $table->text('procedures')->nullable();
            $table->text('indications')->nullable();
            $table->text('next_steps')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['patient_id', 'attended_at']);
        });

        Schema::create('icd10_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('description');
            $table->boolean('is_dental')->default(true);
        });

        Schema::create('consultation_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('icd10_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('tooth', 10)->nullable();
            $table->string('type', 20)->default('definitive');
            $table->timestamps();
        });

        // Catálogo de hallazgos del odontograma (norma técnica del MINSA).
        Schema::create('odontogram_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('abbreviation', 10)->nullable();
            $table->string('applies_to', 10)->default('tooth'); // surface | tooth | span
            $table->json('abbreviations')->nullable(); // siglas permitidas, ej. AM, R, IV
            $table->boolean('allows_good_state')->default(true);
            $table->boolean('allows_bad_state')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        // Una versión por consulta para ver la evolución.
        Schema::create('odontograms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('evolution');
            $table->string('dentition', 20)->default('permanent');
            $table->text('specifications')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['patient_id', 'created_at']);
        });

        Schema::create('odontogram_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('odontogram_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('tooth'); // numeración FDI: 11–48 adultos, 51–85 niños
            $table->string('surface', 2)->nullable(); // V, L, P, M, D, O, I
            $table->foreignId('odontogram_condition_id')->constrained()->restrictOnDelete();
            $table->string('state', 10); // bad = rojo (por tratar) | good = azul (tratado)
            $table->string('abbreviation', 10)->nullable();
            $table->unsignedTinyInteger('to_tooth')->nullable(); // prótesis o aparatos que abarcan varias piezas
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['odontogram_id', 'tooth']);
        });

        // Plan de tratamiento con costos (presupuesto informativo).
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['patient_id', 'status']);
        });

        Schema::create('treatment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('tooth', 10)->nullable();
            $table->string('surfaces', 10)->nullable();
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->unsignedTinyInteger('sessions_planned')->default(1);
            $table->unsignedTinyInteger('sessions_done')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('treatment_plan_item_id')->references('id')->on('treatment_plan_items')->nullOnDelete();
        });

        // Radiografías, fotos clínicas y documentos (disco privado, nunca público).
        Schema::create('clinical_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->date('taken_at')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('consent_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('consent_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body_snapshot');
            $table->string('signer_name');
            $table->string('signer_document', 20)->nullable();
            $table->string('signer_relationship', 20)->default('self');
            $table->string('signature_path')->nullable();
            $table->foreignId('clinical_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('issued_at');
            $table->text('indications')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->string('medication');
            $table->string('presentation')->nullable();
            $table->string('dose')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->string('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('consent_templates');
        Schema::dropIfExists('clinical_files');
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['treatment_plan_item_id']);
        });
        Schema::dropIfExists('treatment_plan_items');
        Schema::dropIfExists('treatment_plans');
        Schema::dropIfExists('odontogram_entries');
        Schema::dropIfExists('odontograms');
        Schema::dropIfExists('odontogram_conditions');
        Schema::dropIfExists('consultation_diagnoses');
        Schema::dropIfExists('icd10_codes');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('medical_backgrounds');
    }
};
