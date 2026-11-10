<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 100);
            $table->string('message', 500);
            $table->integer('people_count')->default(0);
            $table->integer('duration_seconds')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('mqtt_received_at');
            $table->string('mqtt_dedup_key', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
