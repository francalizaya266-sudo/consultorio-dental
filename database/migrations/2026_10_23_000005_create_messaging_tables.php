<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Textos de los avisos, editables por el administrador.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->text('body');
            // Plantilla aprobada por Meta, para cuando se active la API oficial.
            $table->string('api_template_name')->nullable();
            $table->string('api_language', 10)->default('es');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Bandeja de avisos: el panel los prepara y recepción presiona Enviar.
        // Con la API oficial, el mismo registro se envía solo.
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 50);
            $table->string('phone', 20);
            $table->json('params')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('channel', 20)->default('click_to_chat');
            $table->dateTime('due_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('message_templates');
    }
};
