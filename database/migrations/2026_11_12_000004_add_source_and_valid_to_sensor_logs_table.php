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
        Schema::table('sensor_logs', function (Blueprint $table) {
            $table->float('temperature')->nullable()->change();
            $table->float('humidity')->nullable()->change();
            $table->string('source', 20)->default('UNKNOWN')->after('humidity');
            $table->boolean('valid')->default(true)->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sensor_logs', function (Blueprint $table) {
            $table->dropColumn(['source', 'valid']);
        });
    }
};
