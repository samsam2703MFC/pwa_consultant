<?php
namespace App\Consultant\app\Http\Controllers\Report;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Report\ReportShareRepository;

/**
 * GET /r/{jeton} — la page publique d'un rapport partagé.
 *
 * SEUL contrôleur du panel accessible sans session (voir App::$publicControllers).
 * Il ne fait qu'une chose : relire un HTML déjà figé et le servir dans une
 * coquille sans navigation. Il n'appelle pas l'API, ne touche à aucun autre
 * rapport, et ne sait rien faire d'autre.
 *
 * Un jeton mort et un jeton inexistant donnent la même réponse — dire lequel a
 * existé renseignerait qui en essaie au hasard.
 */
class SharedReportController extends Controller
{
    public function __construct(private ReportShareRepository $shares) {}

    public function show(string $token): void
    {
        // L'adresse ne doit ni s'indexer, ni fuir par un clic sortant, ni
        // dormir dans un cache partagé.
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store');

        $share = $this->shares->findValid($token);
        if ($share === null) {
            http_response_code(404);
            $this->view('report/shared_gone', ['active_nav' => null]);
            return;
        }

        $this->shares->touch($token, $this->ip());

        $this->view('report/shared', [
            'share'      => $share,
            'html'       => $share['html'],
            'active_nav' => null,
        ]);
    }

    /**
     * L'adresse de qui ouvre. Derrière un proxy, REMOTE_ADDR est celle du
     * proxy : on prend le PREMIER maillon de X-Forwarded-For, le seul que le
     * client ne contrôle pas librement une fois la chaîne de confiance posée.
     */
    private function ip(): ?string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $premier = trim(explode(',', $xff)[0]);
            if (filter_var($premier, FILTER_VALIDATE_IP)) {
                return $premier;
            }
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
