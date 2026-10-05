<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('type', 16)->default('deploy'); // deploy | rollback | redeploy
            $table->string('trigger', 16)->default('manual'); // manual | webhook
            $table->string('status', 24)->default('queued')->index();
            $table->string('branch')->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->text('commit_message')->nullable();
            $table->string('commit_author')->nullable();
            $table->string('image_tag')->nullable();
            $table->string('image_id')->nullable();
            $table->boolean('image_available')->default(false);
            $table->string('container_id')->nullable();
            $table->string('container_name')->nullable();
            $table->foreignId('rollback_of_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_stage', 24)->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('failure_detail')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('build_started_at')->nullable();
            $table->timestamp('build_finished_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 16)->default('system'); // system | build | health | container
            $table->string('level', 8)->default('info');
            $table->text('line');
            $table->timestamp('logged_at', 3)->useCurrent();

            $table->index(['deployment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_logs');
        Schema::dropIfExists('deployments');
    }
};
