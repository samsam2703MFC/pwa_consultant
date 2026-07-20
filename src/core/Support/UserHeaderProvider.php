<?php
namespace App\Consultant\core\Support;

class UserHeaderProvider
{
    public function getLanguage(): ?string
    {
        return GlobalRegistry::get('lang_code');
    }
}

