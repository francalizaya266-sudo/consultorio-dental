<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opciones rápidas y atajos (/limp). Sin usuario = compartida por todo el equipo.
        Schema::create('text_snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('field', 30)->default('any');
            $table->string('label');
            $table->text('body');
            $table->string('shortcut', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['field', 'user_id']);
            $table->index('shortcut');
        });

        // Cuántas veces usa cada doctor cada opción: las más usadas salen primero.
        Schema::create('text_snippet_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('text_snippet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->unique(['text_snippet_id', 'user_id']);
        });

        // Plantillas por tratamiento: precargan los campos habituales.
        Schema::create('clinical_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_templates');
        Schema::dropIfExists('text_snippet_usages');
        Schema::dropIfExists('text_snippets');
    }
};
