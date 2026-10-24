<?php

namespace App\Exceptions;

use DomainException;

class InvalidStocktakeStateException extends DomainException
{
    public function __construct(string $status)
    {
        parent::__construct("Status opname saat ini ({$status}) tidak mengizinkan tindakan ini.");
    }
}
