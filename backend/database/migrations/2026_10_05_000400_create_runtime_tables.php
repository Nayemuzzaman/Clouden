<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('dns_status', 16)->default('unknown'); // unknown | ok | mismatch | missing
            $table->json('dns_records')->nullable();
            $table->timestamp('dns_checked_at')->nullable();
            $table->string('cert_status', 16)->default('pending'); // pending | active | failed | disabled
            $table->text('cert_error')->nullable();
            $table->timestamp('cert_expires_at')->nullable();
            $table->timestamp('cert_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('environment_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 255);
            $table->text('value'); // encrypted at rest
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_system')->default(false); // managed by PrivateCloud (e.g. DB_*)
            $table->boolean('available_at_build')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('docker_name')->unique();
            $table->string('mount_path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('size_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->unique(['project_id', 'mount_path']);
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('docker_id')->unique();
            $table->string('name');
            $table->string('image');
            $table->string('role', 16)->default('candidate'); // candidate | production | retired
            $table->string('state', 16)->default('created');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_databases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine', 16)->default('postgres');
            $table->string('name', 63)->unique();
            $table->string('username', 63)->unique();
            $table->text('password'); // encrypted
            $table->string('host');
            $table->unsignedInteger('port')->default(5432);
            $table->string('status', 16)->default('provisioning'); // provisioning | ready | failed | deleting
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('sql_query_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('sql');
            $table->boolean('success');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->integer('row_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_query_history');
        Schema::dropIfExists('project_databases');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('volumes');
        Schema::dropIfExists('environment_variables');
        Schema::dropIfExists('domains');
    }
};
