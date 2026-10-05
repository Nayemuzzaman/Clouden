<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->string('status', 32)->default('created')->index();

            // Source & build
            $table->string('source_type', 16)->default('github'); // github | git | image
            $table->string('image')->nullable(); // for source_type=image
            $table->string('dockerfile_path')->default('Dockerfile');
            $table->string('build_context')->default('.');
            $table->unsignedInteger('port')->default(3000);

            // Resources
            $table->unsignedInteger('memory_limit_mb')->default(512);
            $table->decimal('cpu_limit', 5, 2)->default(1.00);

            // Health check
            $table->string('health_check_type', 16)->default('http'); // http | container
            $table->string('health_check_path')->default('/');
            $table->unsignedSmallInteger('health_check_status_min')->default(200);
            $table->unsignedSmallInteger('health_check_status_max')->default(399);
            $table->unsignedSmallInteger('health_check_timeout')->default(5);
            $table->unsignedSmallInteger('health_check_retries')->default(10);
            $table->unsignedSmallInteger('health_check_interval')->default(3);

            // Automation
            $table->boolean('auto_deploy')->default(false);
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->unsignedSmallInteger('image_retention')->default(5);
            $table->string('backup_schedule', 16)->default('off'); // off | daily | weekly
            $table->string('backup_time', 5)->default('03:00');
            $table->unsignedSmallInteger('backup_retention')->default(7);
            $table->timestamp('last_scheduled_backup_at')->nullable();

            $table->unsignedBigInteger('current_deployment_id')->nullable()->index();
            $table->timestamp('deleting_at')->nullable();
            $table->timestamps();
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 16); // github | git
            $table->string('full_name')->nullable(); // owner/repo for GitHub
            $table->string('url');
            $table->string('branch')->default('main');
            $table->string('latest_commit_sha', 64)->nullable();
            $table->text('latest_commit_message')->nullable();
            $table->string('latest_commit_author')->nullable();
            $table->timestamp('latest_commit_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_check_error')->nullable();
            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->timestamps();
        });

        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_login');
            $table->string('account_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('token_type', 16)->default('pat');
            $table->text('token'); // encrypted
            $table->text('scopes')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('projects');
    }
};
