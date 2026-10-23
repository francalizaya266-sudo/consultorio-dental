<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preguntas frecuentes: generales o de un tratamiento (salen en su página).
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Opiniones reales de pacientes (copiadas de Google u otra fuente), nunca inventadas.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('author_name', 80);
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->string('source', 20)->default('google');
            $table->date('reviewed_on')->nullable();
            $table->string('url')->nullable();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Antes y después: solo con la autorización del paciente para publicar sus fotos.
        Schema::create('smile_cases', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('before_path');
            $table->string('after_path');
            $table->timestamp('consent_confirmed_at');
            $table->foreignId('consent_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smile_cases');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('faqs');
    }
};
