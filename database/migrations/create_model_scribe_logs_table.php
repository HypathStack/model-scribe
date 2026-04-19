<?php

use HypathBel\ModelScribe\Enums\ScribeEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('model-scribe.drivers.database.table', 'model_scribe_logs');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            // 🏷️ ORGANIZACIÓN
            $table->string('log_name')->default('default');
            $table->enum('event', array_map(fn ($event) => $event->value, ScribeEvent::cases()));
            $table->text('description')->nullable();

            // 👤 CAUSER (who acted — typically the authenticated user)
            $table->nullableMorphs('causer');   // causer_id, causer_type

            // 📦 SUBJECT (the Eloquent model that was affected)
            $table->nullableMorphs('subject');  // subject_id, subject_type

            // 🔄 CHANGES (old + new attribute values)
            $table->json('properties')->nullable();

            // 🌐 CONTEXTO WEB
            // TO DO optional
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            // 🗂️ AGRUPACIÓN Y ETIQUETAS
            // TO DO optional
            $table->uuid('batch_uuid')->nullable();
            $table->json('tags')->nullable();

            $table->timestamps();

            // Optimised indices
            $table->index('log_name');
            $table->index('event');
            $table->index('batch_uuid');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
        });
    }

    public function down(): void
    {
        $tableName = config('model-scribe.drivers.database.table', 'model_scribe_logs');
        Schema::dropIfExists($tableName);
    }
};
