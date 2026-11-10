<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah user_id ke rooms (setiap ruangan milik pelanggan tertentu)
     * dan serial_number + user_id ke devices (pairing via BLE).
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('room_id')->constrained('users')->cascadeOnDelete();
            $table->string('serial_number')->unique()->nullable()->after('device_type');
            $table->string('firmware_version')->nullable()->after('serial_number');
            $table->boolean('is_paired')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'serial_number', 'firmware_version', 'is_paired']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
