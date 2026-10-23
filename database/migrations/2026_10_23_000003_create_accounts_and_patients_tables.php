<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una cuenta = un celular. Puede tener varios pacientes a cargo (familia).
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique(); // E.164, ej. +51987654321
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('accepts_promotions')->default(false);
            $table->timestamp('promotions_consent_at')->nullable();
            // Consentimiento de tratamiento de datos (Ley 29733).
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('privacy_version', 20)->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason')->nullable();
            $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Tope de citas web futuras para todo el celular; null = valor general.
            $table->unsignedTinyInteger('web_booking_limit')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('relationship', 20)->default('self');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('document_type', 10)->nullable();
            $table->string('document_number', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->char('sex', 1)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->string('occupation')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->string('origin', 30)->nullable();
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->text('admin_notes')->nullable();
            $table->timestamp('first_visit_at')->nullable();
            $table->timestamp('last_visit_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['document_type', 'document_number']);
            $table->index(['last_name', 'first_name']);
        });

        // Dispositivos recordados: reservar con un toque y ver próximas citas.
        Schema::create('remembered_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remembered_devices');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('accounts');
    }
};
