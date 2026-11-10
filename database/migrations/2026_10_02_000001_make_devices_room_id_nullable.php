<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buat devices.room_id nullable agar device dapat di-unpair dari room
 * (room_id = null) tanpa harus dihapus.
 * Device yang unpaired dapat di-pair ulang ke room baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Drop FK lama dulu (CASCADE ke rooms)
            $table->dropForeign(['room_id']);

            // Ubah ke nullable
            $table->unsignedBigInteger('room_id')->nullable()->change();

            // Re-add FK dengan SET NULL agar saat room dihapus, device tidak ikut terhapus
            $table->foreign('room_id')
                  ->references('id')
                  ->on('rooms')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->unsignedBigInteger('room_id')->nullable(false)->change();
            $table->foreign('room_id')
                  ->references('id')
                  ->on('rooms')
                  ->cascadeOnDelete();
        });
    }
};
