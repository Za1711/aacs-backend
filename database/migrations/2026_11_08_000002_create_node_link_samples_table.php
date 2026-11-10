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
        Schema::create('node_link_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('telemetry_seq');
            $table->unsignedBigInteger('telemetry_packet_seq');
            $table->unsignedBigInteger('sent_total');
            $table->unsignedBigInteger('acked_total');
            $table->unsignedBigInteger('failed_total');
            $table->unsignedBigInteger('retries_total');
            $table->decimal('pdr', 6, 3);
            $table->boolean('telemetry_acked');
            $table->boolean('telemetry_received');
            $table->boolean('battery_valid');
            $table->unsignedSmallInteger('battery_mv')->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->smallInteger('rssi_dbm')->nullable();
            $table->boolean('test_complete')->default(false);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['room_id', 'session_id', 'telemetry_seq']);
            $table->index(['room_id', 'session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_link_samples');
    }
};
