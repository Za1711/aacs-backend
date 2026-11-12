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
        Schema::table('node_telemetry_logs', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('valid');
            $table->index(['room_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('node_telemetry_logs', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'received_at']);
            $table->dropColumn('received_at');
        });
    }
};
