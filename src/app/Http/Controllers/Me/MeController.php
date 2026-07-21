<?php
namespace App\Consultant\app\Http\Controllers\Me;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Repositories\Consultant\ConsultantUserRepository;
use App\Consultant\core\Support\GlobalRegistry;

class MeController extends Controller
{
    public function __construct(private ConsultantUserRepository $consultantUsers) {}

    /**
     * GET /me — profil consultant. Identité de session (claims JWT) enrichie
     * par les TABLES DE RÉFÉRENCE de la base locale : user_membership,
     * user_profile, position, position_consultant_areas et of_tag (leviers
     * et couleurs officiels, transversal) — source de vérité, pas de données
     * dupliquées.
     */
    public function index(): void
    {
        $user = GlobalRegistry::get('user');
        $membershipId = (int)($user['membership_id'] ?? 0);
        $data = $this->consultantUsers->getConsultantData($membershipId);

        $firstName = $this->firstText($data['profile'], ['first_name', 'firstname', 'given_name']);
        $lastName  = $this->firstText($data['profile'], ['last_name', 'lastname', 'surname', 'family_name']);
        $fullName  = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        $this->view('me/profile', [
            'consultant' => [
                'full_name' => $fullName !== '' ? $fullName : null,
                'position'  => $this->firstText($data['position'], ['name', 'title', 'label', 'position_name']),
                'level'     => $this->firstText($data['position'], ['level_name', 'level', 'grade']),
                'areas'     => $data['areas'],
                'email'     => $this->firstText($data['profile'], ['email', 'mail', 'email_address']),
                'phone'     => $this->firstText($data['profile'], ['phone', 'phone_number', 'mobile', 'gsm', 'telephone']),
            ],
            'of_tags'    => $this->consultantUsers->getOfficialTags(),
            'active_nav' => 'profile',
        ]);
    }

    /** Première valeur texte non vide parmi les colonnes candidates. */
    private function firstText(array $row, array $candidates): ?string
    {
        foreach ($candidates as $cand) {
            foreach ($row as $col => $val) {
                if (strcasecmp((string)$col, $cand) === 0 && is_string($val) && trim($val) !== '') {
                    return trim($val);
                }
            }
        }
        return null;
    }
}
