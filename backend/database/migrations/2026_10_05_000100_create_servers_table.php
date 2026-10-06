<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // V1 manages exactly one server (the local host). Every project references a
        // server so a multi-node control plane can be added later.
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 32)->default('local');
            $table->boolean('is_local')->default(true);
            $table->string('public_ipv4', 45)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->string('status', 32)->default('online');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
