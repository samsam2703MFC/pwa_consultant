<?php
namespace App\Consultant\app\Http\Requests;

class LoginRequest {

    public static function validateLogin(array $data): array
    {
        $errors = [];

        if (empty($data['phone'])) {
            $errors['phone'] = 'Phone is required';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        }

        return $errors;
    }
}

