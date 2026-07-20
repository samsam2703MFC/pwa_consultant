<?php
namespace App\Consultant\core\Exceptions;

use Exception;

class ProtectedResourceException extends Exception
{
    public function __construct(string $message = "Element nie może zostać usunięty", int $code = 403, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

