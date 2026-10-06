<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub signs the request body but not the X-GitHub-Delivery header, so a
     * captured delivery could be replayed with a new delivery id. A unique hash
     * of the signed body per project rejects such replays.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('payload_sha256', 64)->nullable()->after('delivery_id');
            $table->unique(['project_id', 'payload_sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'payload_sha256']);
            $table->dropColumn('payload_sha256');
        });
    }
};
