<?php
namespace App\Consultant\app\Http\Controllers\Dashboard;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\core\Support\Route;

class DashboardController extends Controller
{
    #[Route('GET', '/dashboard')]
    public function index(): void
    {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $this->view('dashboard/dashboard', [
            'date'       => $date,
            'active_nav' => 'dashboard',
        ]);
    }
}

