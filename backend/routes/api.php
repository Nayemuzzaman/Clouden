<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\ContainerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DatabaseController;
use App\Http\Controllers\Api\V1\DeploymentController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\EnvironmentController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Controllers\Api\V1\ProjectActionController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SqlController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\VolumeController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ---- GitHub webhooks: no session, authenticated by HMAC signature -----
    Route::post('webhooks/github/{uuid}', [WebhookController::class, 'github'])
        ->whereUuid('uuid')->middleware('throttle:webhooks');

    // The dashboard is a same-origin SPA: it uses the regular session cookie
    // (HttpOnly, SameSite) plus Laravel's XSRF-TOKEN double-submit CSRF protection.
    Route::middleware('web')->group(function () {
        Route::get('auth/csrf', fn () => response()->noContent());
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

        // ---- Authenticated administrator --------------------------------------
        Route::middleware(['auth:web', 'admin', 'throttle:api'])->group(function () {
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::post('auth/logout', [AuthController::class, 'logout']);
            Route::post('auth/confirm-password', [AuthController::class, 'confirmPassword'])->middleware('throttle:login');
            Route::put('auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');
            // Preflight used by the dashboard before plain-link downloads that require a recent password.
            Route::post('auth/recent', fn () => response()->noContent())->middleware('password.recent');

            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('search', [DashboardController::class, 'search']);

            // Projects
            Route::get('projects', [ProjectController::class, 'index']);
            Route::post('projects', [ProjectController::class, 'store']);
            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::patch('projects/{project}', [ProjectController::class, 'update']);
            Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
            Route::get('projects/{project}/deletion-impact', [ProjectController::class, 'deletionImpact']);

            Route::post('projects/{project}/start', [ProjectActionController::class, 'start']);
            Route::post('projects/{project}/stop', [ProjectActionController::class, 'stop']);
            Route::post('projects/{project}/restart', [ProjectActionController::class, 'restart']);
            Route::get('projects/{project}/container', [ProjectActionController::class, 'container']);
            Route::get('projects/{project}/metrics', [ProjectActionController::class, 'metrics']);
            Route::post('projects/{project}/refresh-commit', [ProjectActionController::class, 'refreshCommit']);
            Route::get('projects/{project}/detect', [ProjectActionController::class, 'detect']);
            Route::put('projects/{project}/auto-deploy', [ProjectActionController::class, 'autoDeploy']);
            Route::get('projects/{project}/webhook', [ProjectActionController::class, 'webhook']);
            Route::post('projects/{project}/webhook/reveal', [ProjectActionController::class, 'revealWebhookSecret'])->middleware(['password.recent', 'throttle:sensitive']);
            Route::post('projects/{project}/webhook/rotate', [ProjectActionController::class, 'rotateWebhookSecret'])->middleware('password.recent');

            // Deployments
            Route::get('projects/{project}/deployments', [DeploymentController::class, 'index']);
            Route::post('projects/{project}/deployments', [DeploymentController::class, 'store']);
            Route::post('projects/{project}/redeploy', [DeploymentController::class, 'redeploy']);
            Route::scopeBindings()->group(function () {
                Route::get('projects/{project}/deployments/{deployment}', [DeploymentController::class, 'show']);
                Route::get('projects/{project}/deployments/{deployment}/logs', [DeploymentController::class, 'logs']);
                Route::get('projects/{project}/deployments/{deployment}/container-logs', [DeploymentController::class, 'containerLogs']);
                Route::post('projects/{project}/deployments/{deployment}/rollback', [DeploymentController::class, 'rollback']);
                Route::post('projects/{project}/deployments/{deployment}/cancel', [DeploymentController::class, 'cancel']);
            });

            // Logs
            Route::get('projects/{project}/logs', [LogController::class, 'index']);
            Route::get('projects/{project}/logs/download', [LogController::class, 'download']);

            // Environment
            Route::get('projects/{project}/environment', [EnvironmentController::class, 'index']);
            Route::post('projects/{project}/environment', [EnvironmentController::class, 'store']);
            Route::post('projects/{project}/environment/import', [EnvironmentController::class, 'import']);
            Route::put('projects/{project}/environment/{variable}', [EnvironmentController::class, 'update']);
            Route::delete('projects/{project}/environment/{variable}', [EnvironmentController::class, 'destroy']);
            Route::post('projects/{project}/environment/{variable}/reveal', [EnvironmentController::class, 'reveal'])->middleware(['password.recent', 'throttle:sensitive']);

            // Domains
            Route::get('domains', [DomainController::class, 'all']);
            Route::get('projects/{project}/domains', [DomainController::class, 'index']);
            Route::post('projects/{project}/domains', [DomainController::class, 'store']);
            Route::delete('projects/{project}/domains/{domain}', [DomainController::class, 'destroy']);
            Route::post('projects/{project}/domains/{domain}/check', [DomainController::class, 'check']);
            Route::post('projects/{project}/domains/{domain}/primary', [DomainController::class, 'primary']);

            // Storage
            Route::get('volumes', [VolumeController::class, 'all']);
            Route::get('projects/{project}/volumes', [VolumeController::class, 'index']);
            Route::post('projects/{project}/volumes', [VolumeController::class, 'store']);
            Route::delete('projects/{project}/volumes/{volume}', [VolumeController::class, 'destroy']);

            // Databases
            Route::get('databases', [DatabaseController::class, 'index']);
            Route::post('databases', [DatabaseController::class, 'store']);
            Route::post('projects/{project}/database', [DatabaseController::class, 'storeForProject']);
            Route::get('databases/{database}', [DatabaseController::class, 'show']);
            Route::delete('databases/{database}', [DatabaseController::class, 'destroy']);
            Route::post('databases/{database}/reveal', [DatabaseController::class, 'reveal'])->middleware(['password.recent', 'throttle:sensitive']);
            Route::post('databases/{database}/reset-credentials', [DatabaseController::class, 'resetCredentials'])->middleware('password.recent');
            Route::post('databases/{database}/retry', [DatabaseController::class, 'retry']);

            Route::get('databases/{database}/schemas', [TableController::class, 'schemas']);
            Route::get('databases/{database}/tables', [TableController::class, 'index']);
            Route::post('databases/{database}/tables', [TableController::class, 'store']);
            Route::get('databases/{database}/tables/{table}', [TableController::class, 'show']);
            Route::delete('databases/{database}/tables/{table}', [TableController::class, 'destroy']);
            Route::get('databases/{database}/tables/{table}/rows', [TableController::class, 'rows']);
            Route::post('databases/{database}/tables/{table}/rows', [TableController::class, 'insertRow']);
            Route::patch('databases/{database}/tables/{table}/rows', [TableController::class, 'updateRow']);
            Route::delete('databases/{database}/tables/{table}/rows', [TableController::class, 'deleteRow']);
            Route::post('databases/{database}/tables/{table}/columns', [TableController::class, 'addColumn']);
            Route::patch('databases/{database}/tables/{table}/columns/{column}', [TableController::class, 'updateColumn']);
            Route::delete('databases/{database}/tables/{table}/columns/{column}', [TableController::class, 'dropColumn']);

            Route::post('databases/{database}/sql', [SqlController::class, 'run'])->middleware('throttle:sensitive');
            Route::get('databases/{database}/sql/history', [SqlController::class, 'history']);

            // Backups
            Route::get('backups', [BackupController::class, 'index']);
            Route::get('backups/summary', [BackupController::class, 'summary']);
            Route::post('backups', [BackupController::class, 'store']);
            Route::get('backups/{backup}', [BackupController::class, 'show']);
            Route::delete('backups/{backup}', [BackupController::class, 'destroy']);
            Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('password.recent');
            Route::get('backups/{backup}/download', [BackupController::class, 'download'])->middleware(['password.recent', 'throttle:sensitive']);
            Route::get('operations/{operation}', [OperationController::class, 'show']);

            // Server
            Route::get('containers', [ContainerController::class, 'index']);
            Route::get('server/metrics', [ServerController::class, 'metrics']);
            Route::get('server/metrics/history', [ServerController::class, 'history']);
            Route::get('server/services', [ServerController::class, 'services']);
            Route::post('server/cleanup', [ServerController::class, 'cleanup']);

            // Settings & GitHub
            Route::get('settings', [SettingsController::class, 'show']);
            Route::put('settings/thresholds', [SettingsController::class, 'updateThresholds']);
            Route::put('settings/server', [SettingsController::class, 'updateServer']);
            Route::post('settings/github', [SettingsController::class, 'connectGithub'])->middleware('password.recent');
            Route::delete('settings/github', [SettingsController::class, 'disconnectGithub']);
            Route::get('github/repositories', [SettingsController::class, 'repositories']);
            Route::get('github/repositories/{owner}/{repo}/branches', [SettingsController::class, 'branches']);

            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
        });
    });

    Route::any('{any}', fn () => response()->json(['message' => 'Not found.'], 404))->where('any', '.*');
});
