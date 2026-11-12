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
            $table->index(['room_id', 'received_at'], 'node_link_samples_room_id_received_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('node_link_samples', function (Blueprint $table) {
            $table->dropIndex('node_link_samples_room_id_received_at_index');
        });
    }
};
