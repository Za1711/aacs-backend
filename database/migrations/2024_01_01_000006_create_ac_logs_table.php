<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ac_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->enum('power', ['ON', 'OFF']);
            $table->integer('setpoint');
            $table->string('fan_speed')->default('MEDIUM'); // Fixed value
            $table->enum('mode', ['FUZZY', 'MANUAL']);
            $table->string('swing')->default('AUTO'); // Fixed value
            $table->timestamp('created_at')->useCurrent();

            $table->index(['room_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ac_logs');
    }
};
