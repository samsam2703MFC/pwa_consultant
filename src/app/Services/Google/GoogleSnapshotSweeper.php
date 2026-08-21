<?php
namespace App\Consultant\app\Services\Google;

use App\Consultant\app\Repositories\Google\GoogleRatingRepository;
use App\Consultant\app\Repositories\Google\GoogleRatingSnapshotRepository;
use App\Consultant\app\Services\Param\ParamService;
use App\Consultant\app\Services\Shop\ShopService;
use Throwable;

/**
 * Complète le relevé Google du mois pour TOUS les magasins.
 *
 * POURQUOI PAS UN CRON. Lire une note Google demande la liste des boutiques,
 * qui vient de l'API — donc un jeton. Un cron n'en a pas : il tomberait sur
 * l'écran de connexion. La balayage se greffe donc sur une requête d'un
 * consultant déjà authentifié, APRÈS que la page est partie (le même
 * `fastcgi_finish_request()` que la mesure de performance). L'utilisateur
 * n'attend rien.
 *
 * POURQUOI IL FAUT UN BALAYAGE. Le relevé opportuniste ne s'écrivait que pour
 * les magasins dont on consultait la note. Un magasin qu'on n'ouvre jamais, ou
 * un mois où personne ne passe sur la liste, laissait un trou — et un trou dans
 * cette série ne se rattrape pas : Google ne rend que le présent.
 *
 * Une fois par jour au plus, et seulement pour les magasins qui MANQUENT au
 * mois en cours : sur un mois déjà complet, le balayage ne fait rien et ne
 * consomme aucun quota.
 */
class GoogleSnapshotSweeper
{
    /** Le paramètre qui porte la date du dernier balayage. */
    private const CLE_DERNIER = 'google_sweep_last';

    public function __construct(
        private ShopService $shops,
        private GoogleRatingRepository $ratings,
        private GoogleRatingSnapshotRepository $snapshots,
        private ParamService $params
    ) {}

    /**
     * Balaye si nécessaire. Rend le compte de relevés écrits.
     *
     * `$force` court-circuite la garde quotidienne — pour l'écran de
     * vérification, qui doit pouvoir constater sur demande.
     */
    public function sweep(bool $force = false): array
    {
        $mois = date('Y-m');
        $aujourdhui = date('Y-m-d');

        if (!$force && $this->params->getString(self::CLE_DERNIER, '') === $aujourdhui) {
            return ['fait' => false, 'raison' => 'déjà balayé aujourd’hui', 'ecrits' => 0, 'manquants' => 0];
        }

        try {
            $boutiques = $this->shops->getAllShops();
        } catch (Throwable $e) {
            return ['fait' => false, 'raison' => 'liste des boutiques indisponible', 'ecrits' => 0, 'manquants' => 0];
        }
        if ($boutiques === []) {
            return ['fait' => false, 'raison' => 'aucune boutique', 'ecrits' => 0, 'manquants' => 0];
        }

        // La garde est posée AVANT les appels Google : si l'un d'eux échoue, on
        // ne recommence pas la rafale à chaque requête de la journée.
        $this->params->set(self::CLE_DERNIER, $aujourdhui);

        $ecrits = 0;
        $manquants = 0;
        foreach ($boutiques as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            // Déjà relevé ce mois-ci : on ne rappelle pas Google pour rien.
            if ($this->snapshots->forMonth($id, $mois) !== null) {
                continue;
            }
            $manquants++;

            $nom = (string)($s['representative_name'] ?? $s['name'] ?? '');
            $ville = (string)($s['city'] ?? '');
            $adresse = '';
            foreach (['google_address', 'address', 'full_address', 'formatted_address'] as $k) {
                if (!empty($s[$k])) { $adresse = (string)$s[$k]; break; }
            }
            $placeId = null;
            foreach (['google_place_id', 'place_id'] as $k) {
                if (!empty($s[$k])) { $placeId = (string)$s[$k]; break; }
            }
            if ($nom === '' && $adresse === '' && $placeId === null) {
                continue;
            }

            try {
                $note = $this->ratings->getRating($id, $nom, $ville, $adresse, $placeId);
            } catch (Throwable $e) {
                continue;                       // un magasin qui échoue n'arrête pas les autres
            }
            if (!is_array($note)) {
                continue;
            }
            if ($this->snapshots->record($id, $note['rating'] ?? null, (int)($note['reviews'] ?? 0), $mois)) {
                $ecrits++;
            }
        }

        return ['fait' => true, 'raison' => null, 'ecrits' => $ecrits, 'manquants' => $manquants,
                'mois' => $mois, 'boutiques' => count($boutiques)];
    }
}
