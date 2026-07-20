<?php
namespace App\Consultant\core\Exceptions;

use Exception;

class DataNotFoundException extends Exception
{
    public function __construct(string $message = "Nie znaleziono wymaganych danych", int $code = 404, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

