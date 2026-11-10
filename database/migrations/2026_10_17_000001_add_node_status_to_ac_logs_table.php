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
        Schema::table('ac_logs', function (Blueprint $table) {
            $table->string('control_source')->default('REMOTE')->after('swing');
            $table->boolean('node_paired')->default(false)->after('control_source');
            $table->boolean('node_connected')->default(false)->after('node_paired');
            $table->boolean('node_battery_low')->default(false)->after('node_connected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ac_logs', function (Blueprint $table) {
            $table->dropColumn(['control_source', 'node_paired', 'node_connected', 'node_battery_low']);
        });
    }
};
