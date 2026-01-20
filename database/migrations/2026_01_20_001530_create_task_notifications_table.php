<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('task_id')->nullable();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('prioridad')->default('Alta');
            $table->string('asignado');
            $table->string('creador');
            $table->string('proyecto')->nullable();
            $table->date('fecha_limite')->nullable();
            $table->string('enviado_a');
            $table->string('twilio_sid')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'delivered', 'read'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('task_id');
            $table->index('status');
            $table->index('enviado_a');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_notifications');
    }
};