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
        Schema::create('control_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->float('temperature'); // Fuzzy input
            $table->float('humidity'); // Monitoring only, NOT fuzzy input
            $table->integer('people_count'); // Fuzzy input
            $table->float('fuzzy_output'); // Raw fuzzy output value
            $table->integer('resulting_setpoint'); // Actual setpoint sent to AC
            $table->timestamp('created_at')->useCurrent();

            $table->index(['room_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_logs');
    }
};
