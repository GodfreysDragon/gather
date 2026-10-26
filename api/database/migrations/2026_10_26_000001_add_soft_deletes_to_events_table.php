<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleted events stay as tombstones so the `updated_since` feed can tell sync
     * clients about deletions.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropSoftDeletes();
        });
    }
};
