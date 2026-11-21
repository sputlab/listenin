<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Security;
use ListenIn\Domain\ReportService;
use ListenIn\Domain\Taxonomy;

final class MetaController
{
    public static function meta(Request $r): void
    {
        Response::json([
            'app' => [
                'name' => Config::get('app.name'),
                'tagline' => Config::get('app.tagline'),
                'version' => LISTENIN_VERSION,
                'anonymous_reports' => (bool) Config::get('reports.allow_anonymous', true),
                'export_max_rows' => (int) Config::get('export.max_rows', 20000),
                'password_min_length' => (int) Config::get('security.password_min_length', 10),
            ],
            'taxonomy' => Taxonomy::forClient(),
            'removal_reasons' => ReportService::REMOVAL_REASONS,
            'update_fields' => ReportService::UPDATE_FIELDS,
            'user' => Auth::publicView(Auth::user()),
            'csrf' => Security::csrfToken(),
        ]);
    }

    /** Testi legali: contenuto statico versionato in src/Content. */
    public static function content(Request $r, string $doc): void
    {
        $docs = ['disclaimer' => 'Disclaimer e avvertenze', 'termini' => 'Termini di utilizzo', 'privacy' => 'Informativa privacy'];
        if (!isset($docs[$doc])) {
            throw ApiException::notFound();
        }
        $html = (string) file_get_contents(LISTENIN_ROOT . '/src/Content/' . $doc . '.html');
        Response::json([
            'title' => $docs[$doc],
            'version' => $doc === 'disclaimer' ? Config::get('legal.disclaimer_version') : Config::get('legal.terms_version'),
            'html' => $html,
        ]);
    }
}
