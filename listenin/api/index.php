<?php
/**
 * ListenIn — front controller dell'API REST/JSON.
 */
declare(strict_types=1);

require (require dirname(__DIR__) . '/app-path.php') . '/src/bootstrap.php';

use ListenIn\Controllers\AccountController;
use ListenIn\Controllers\AdminController;
use ListenIn\Controllers\AuthController;
use ListenIn\Controllers\FrequencyController;
use ListenIn\Controllers\CommunityController;
use ListenIn\Controllers\GeoController;
use ListenIn\Controllers\ImportController;
use ListenIn\Controllers\NewsController;
use ListenIn\Controllers\WebhookController;
use ListenIn\Controllers\MetaController;
use ListenIn\Controllers\ModerationController;
use ListenIn\Controllers\ReportController;
use ListenIn\Core\ApiException;
use ListenIn\Core\Maintenance;
use ListenIn\Core\Migrator;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Router;
use ListenIn\Core\Security;
use ListenIn\Core\Session;
use ListenIn\Domain\UserService;

Security::sendHeaders();

try {
    // dopo un aggiornamento dei file, la prima richiesta porta il database alla versione corretta
    try {
        Migrator::ensureUpToDate();
    } catch (\Throwable $e) {
        error_log('[listenin] migrazione non riuscita: ' . $e->getMessage());
        throw new ApiException(503, 'db_migration_failed', 'Aggiornamento del database non riuscito. L\'amministratore può vedere il dettaglio in app/storage/logs/php-error.log.');
    }
    Session::start();
    UserService::enforceSessionValidity();
    Maintenance::maybeSchedule();
    // consegna dei webhook dopo la risposta: il visitatore non aspetta
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            \ListenIn\Domain\WebhookService::tick();
        } catch (\Throwable $e) {
            error_log('[listenin-webhook] ' . $e->getMessage());
        }
    });
    $request = Request::fromGlobals();
    if ($request->isMutating()) {
        Security::verifyCsrf($request);
    }

    $router = new Router();
    // Pubblico, sola lettura
    $router->add('GET', '/meta', [MetaController::class, 'meta']);
    $router->add('GET', '/content/disclaimer', static fn($r) => MetaController::content($r, 'disclaimer'));
    $router->add('GET', '/content/termini', static fn($r) => MetaController::content($r, 'termini'));
    $router->add('GET', '/content/privacy', static fn($r) => MetaController::content($r, 'privacy'));
    $router->add('GET', '/frequencies', [FrequencyController::class, 'index']);
    $router->add('GET', '/frequencies/{id}', [FrequencyController::class, 'show']);
    $router->add('GET', '/frequencies/{id}/history', [FrequencyController::class, 'history']);
    $router->add('GET', '/export', [FrequencyController::class, 'export']);
    // Contributi e segnalazioni
    $router->add('GET', '/frequencies/check', [FrequencyController::class, 'check']);
    $router->add('POST', '/frequencies', [FrequencyController::class, 'create']);
    $router->add('PUT', '/frequencies/{id}', [FrequencyController::class, 'update']);
    $router->add('DELETE', '/frequencies/{id}', [FrequencyController::class, 'destroy']);
    $router->add('POST', '/frequencies/{id}/restore', [FrequencyController::class, 'restore']);
    $router->add('POST', '/frequencies/{id}/reports', [ReportController::class, 'store']);
    // Ascolti, comunità, statistiche, feed e open data
    $router->add('POST', '/frequencies/{id}/checkins', [CommunityController::class, 'checkin']);
    $router->add('GET', '/account/logbook', [CommunityController::class, 'logbook']);
    $router->add('GET', '/account/logbook/export', [CommunityController::class, 'logbookExport']);
    $router->add('GET', '/account/badges', [CommunityController::class, 'badges']);
    $router->add('GET', '/stats', [CommunityController::class, 'stats']);
    $router->add('GET', '/leaderboard', [CommunityController::class, 'leaderboard']);
    $router->add('GET', '/feed', [CommunityController::class, 'feed']);
    $router->add('GET', '/opendata', [CommunityController::class, 'opendata']);
    $router->add('GET', '/opendata/download', [CommunityController::class, 'opendataDownload']);
    $router->add('GET', '/admin/system', [CommunityController::class, 'system']);
    $router->add('POST', '/admin/system/migrate', [CommunityController::class, 'migrate']);
    $router->add('POST', '/admin/opendata/generate', [CommunityController::class, 'generateSnapshot']);
    // Mappe, luoghi e postazioni
    $router->add('GET', '/geo/summary', [GeoController::class, 'summary']);
    $router->add('GET', '/geo/place', [GeoController::class, 'place']);
    $router->add('GET', '/sites', [GeoController::class, 'sites']);
    $router->add('GET', '/sites/suggest', [GeoController::class, 'siteSuggest']);
    $router->add('GET', '/sites/show', [GeoController::class, 'siteShow']);
    $router->add('PUT', '/sites/{id}', [GeoController::class, 'siteUpdate']);
    $router->add('POST', '/admin/geo/comuni', [ImportController::class, 'geoComuni']);
    // Notizie e webhook
    $router->add('GET', '/news', [NewsController::class, 'index']);
    $router->add('GET', '/news/show', [NewsController::class, 'show']);
    $router->add('GET', '/news/feed', [NewsController::class, 'feed']);
    $router->add('GET', '/admin/news', [NewsController::class, 'adminList']);
    $router->add('GET', '/admin/news/show', [NewsController::class, 'adminShow']);
    $router->add('POST', '/admin/news', [NewsController::class, 'create']);
    $router->add('POST', '/admin/news/from-import', [NewsController::class, 'fromImport']);
    $router->add('PUT', '/admin/news/{id}', [NewsController::class, 'update']);
    $router->add('DELETE', '/admin/news/{id}', [NewsController::class, 'delete']);
    $router->add('GET', '/account/webhooks', [WebhookController::class, 'index']);
    $router->add('POST', '/account/webhooks', [WebhookController::class, 'create']);
    $router->add('PUT', '/account/webhooks/{id}', [WebhookController::class, 'update']);
    $router->add('DELETE', '/account/webhooks/{id}', [WebhookController::class, 'delete']);
    $router->add('POST', '/account/webhooks/{id}/test', [WebhookController::class, 'test']);
    $router->add('POST', '/account/webhooks/{id}/rotate', [WebhookController::class, 'rotate']);
    $router->add('GET', '/account/webhooks/{id}/deliveries', [WebhookController::class, 'deliveries']);
    $router->add('GET', '/admin/webhooks', [WebhookController::class, 'adminList']);
    $router->add('POST', '/admin/webhooks/{id}/disable', [WebhookController::class, 'adminDisable']);
    // Autenticazione
    $router->add('GET', '/auth/me', [AuthController::class, 'me']);
    $router->add('POST', '/auth/register', [AuthController::class, 'register']);
    $router->add('POST', '/auth/verify', [AuthController::class, 'verify']);
    $router->add('POST', '/auth/resend', [AuthController::class, 'resend']);
    $router->add('POST', '/auth/login', [AuthController::class, 'login']);
    $router->add('POST', '/auth/logout', [AuthController::class, 'logout']);
    $router->add('POST', '/auth/password/forgot', [AuthController::class, 'forgot']);
    $router->add('POST', '/auth/password/reset', [AuthController::class, 'reset']);
    // Account self-service
    $router->add('GET', '/account', [AccountController::class, 'show']);
    $router->add('PATCH', '/account', [AccountController::class, 'update']);
    $router->add('POST', '/account/email', [AccountController::class, 'email']);
    $router->add('POST', '/account/email/confirm', [AccountController::class, 'emailConfirm']);
    $router->add('POST', '/account/password', [AccountController::class, 'password']);
    $router->add('POST', '/account/disclaimer', [AccountController::class, 'disclaimer']);
    $router->add('GET', '/account/contributions', [AccountController::class, 'contributions']);
    $router->add('GET', '/account/reports', [AccountController::class, 'reports']);
    $router->add('POST', '/account/delete', [AccountController::class, 'delete']);
    // Moderazione
    $router->add('GET', '/moderation/stats', [ModerationController::class, 'stats']);
    $router->add('GET', '/moderation/revisions', [ModerationController::class, 'revisions']);
    $router->add('GET', '/moderation/revisions/{id}', [ModerationController::class, 'revision']);
    $router->add('POST', '/moderation/revisions/{id}/approve', [ModerationController::class, 'approve']);
    $router->add('POST', '/moderation/revisions/{id}/reject', [ModerationController::class, 'reject']);
    $router->add('GET', '/moderation/reports', [ModerationController::class, 'reports']);
    $router->add('POST', '/moderation/reports/{id}/claim', [ModerationController::class, 'claim']);
    $router->add('POST', '/moderation/reports/{id}/resolve', [ModerationController::class, 'resolve']);
    $router->add('POST', '/moderation/reports/{id}/archive', [ModerationController::class, 'archive']);
    // Amministrazione
    $router->add('GET', '/admin/users', [AdminController::class, 'users']);
    $router->add('PATCH', '/admin/users/{id}', [AdminController::class, 'updateUser']);

    // Importazione dal pannello (solo amministratori)
    $router->add('GET', '/admin/import', [ImportController::class, 'overview']);
    $router->add('POST', '/admin/import/upload', [ImportController::class, 'upload']);
    $router->add('POST', '/admin/import/delete', [ImportController::class, 'deleteFile']);
    $router->add('POST', '/admin/import/start', [ImportController::class, 'start']);
    $router->add('POST', '/admin/import/step', [ImportController::class, 'step']);
    $router->add('GET', '/admin/import/job', [ImportController::class, 'job']);
    $router->add('POST', '/admin/import/cancel', [ImportController::class, 'cancel']);
    $router->add('GET', '/admin/import/download', [ImportController::class, 'download']);
    $router->add('POST', '/admin/import/fetch', [ImportController::class, 'fetch']);
    $router->add('POST', '/admin/import/comuni', [ImportController::class, 'comuni']);
    $router->add('POST', '/admin/maintenance', [ImportController::class, 'maintenance']);

    $router->dispatch($request);
} catch (ApiException $e) {
    Response::error($e);
} catch (\Throwable $e) {
    error_log('[listenin] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error(new ApiException(500, 'server_error', 'Errore interno. Riprova più tardi.'));
}
