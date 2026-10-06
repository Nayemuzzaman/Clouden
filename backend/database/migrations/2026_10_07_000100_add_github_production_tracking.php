<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State needed to keep a production branch linked to the live deployment:
 *  - repositories: visibility, the result of the last access check, and the
 *    state of the webhook PrivateCloud manages;
 *  - projects: an explicit "rolled back" hold, so an intentional rollback is
 *    not undone by auto deploy until a NEW commit is pushed;
 *  - deployments: where the code came from (repository, visibility, delivery);
 *  - webhook_events: repository and the previous head ("before"), to detect
 *    deliveries that arrive out of order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('visibility', 16)->nullable()->after('branch'); // public | private
            $table->string('access_status', 32)->nullable()->after('last_check_error'); // ok | auth_failed | no_access | no_contents_permission | branch_missing | rate_limited | unreachable
            $table->string('webhook_status', 16)->nullable()->after('webhook_id'); // active | failed | manual | orphaned
            $table->text('webhook_error')->nullable()->after('webhook_status');
            $table->string('webhook_url')->nullable()->after('webhook_error');
            $table->timestamp('webhook_last_delivery_at')->nullable()->after('webhook_url');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('rolled_back_at')->nullable()->after('current_deployment_id');
            $table->string('rollback_hold_sha', 64)->nullable()->after('rolled_back_at');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->string('repository')->nullable()->after('branch');
            $table->string('source_visibility', 16)->nullable()->after('repository');
            $table->timestamp('commit_committed_at')->nullable()->after('commit_author');
            $table->string('webhook_delivery_id', 100)->nullable()->after('initiated_by');
            $table->index(['project_id', 'commit_sha']);
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('repository')->nullable()->after('event');
            $table->string('before_sha', 64)->nullable()->after('ref');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['repository', 'before_sha']);
        });
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'commit_sha']);
            $table->dropColumn(['repository', 'source_visibility', 'commit_committed_at', 'webhook_delivery_id']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['rolled_back_at', 'rollback_hold_sha']);
        });
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'access_status', 'webhook_status', 'webhook_error', 'webhook_url', 'webhook_last_delivery_at']);
        });
    }
};
