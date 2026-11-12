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
        Schema::table('node_link_samples', function (Blueprint $table) {
            $table->boolean('sensor_valid')->default(false)->after('telemetry_received');
            $table->decimal('temperature_c', 5, 2)->nullable()->after('sensor_valid');
            $table->decimal('humidity_percent', 5, 2)->nullable()->after('temperature_c');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('node_link_samples', function (Blueprint $table) {
            $table->dropColumn(['sensor_valid', 'temperature_c', 'humidity_percent']);
        });
    }
};
