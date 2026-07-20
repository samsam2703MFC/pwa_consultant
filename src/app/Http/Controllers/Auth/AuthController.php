<?php
namespace App\Consultant\app\Http\Controllers\Auth;

use App\Consultant\app\Http\Controllers\Controller;
use App\Consultant\app\Http\Requests\LoginRequest;
use App\Consultant\app\Services\Auth\AuthService;
use App\Consultant\core\Support\Route;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    #[Route('GET', '/auth')]
    public function index(): void
    {
        if ($this->authService->isAuthenticated()) {
            redirect('/dashboard');
            return;
        }

        $this->view('auth/login');
    }

    #[Route('POST', '/auth')]
    public function login(): void
    {
        $this->errors = LoginRequest::validateLogin($_POST);

        if (!empty($this->errors)) {
            $this->view('auth/login');
            return;
        }

        $result = $this->authService->login($_POST);

        if ($result['success']) {
            redirect('/dashboard');
            return;
        }

        $errorCode = $result['error_code'] ?? null;

        $this->errors['login_error'] = $errorCode;
        $this->view('auth/login');
    }

    #[Route('GET', '/logout')]
    public function logout(): void
    {
        $this->authService->logout();
        redirect('/auth');
    }
}

