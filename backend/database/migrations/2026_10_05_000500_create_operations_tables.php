<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_database_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('volume_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16); // database | volume
            $table->string('trigger', 16)->default('manual'); // manual | scheduled | pre_restore
            $table->string('status', 16)->default('queued')->index(); // queued | running | success | failed
            $table->string('label')->nullable(); // human readable source, kept after the source is deleted
            $table->string('storage', 32)->default('local');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        // Generic long-running operation tracking (restores, project deletion, ...).
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 48);
            $table->string('status', 16)->default('queued'); // queued | running | success | failed
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('target');
            $table->string('message')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('cpu_percent', 6, 2)->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('memory_total_bytes')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->decimal('load_1', 8, 2)->nullable();
            $table->unsignedBigInteger('net_rx_bytes')->nullable();
            $table->unsignedBigInteger('net_tx_bytes')->nullable();
            $table->timestamp('recorded_at')->index();

            $table->index(['server_id', 'project_id', 'recorded_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('resource_type', 48)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('resource_label')->nullable();
            $table->string('result', 16)->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16)->default('github');
            $table->string('delivery_id')->unique();
            $table->string('event', 48);
            $table->string('ref')->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('status', 16); // accepted | ignored | rejected
            $table->string('reason')->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('metrics');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('backups');
    }
};
