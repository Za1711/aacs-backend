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
        Schema::create('node_battery_statuses', function (Blueprint $table) {
            $table->foreignId('room_id')->primary()->constrained('rooms')->cascadeOnDelete();
            $table->boolean('valid')->default(false);
            $table->integer('millivolts')->nullable();
            $table->integer('percent')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_battery_statuses');
    }
};
