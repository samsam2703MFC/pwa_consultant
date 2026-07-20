<?php
namespace App\Consultant\app\Http\Controllers\Me;

use App\Consultant\app\Http\Controllers\Controller;

class MeController extends Controller
{
    /**
     * GET /me — profil konsultanta z danych sesji (claims JWT).
     */
    public function index(): void
    {
        $this->view('me/profile', [
            'active_nav' => 'profile',
        ]);
    }
}
