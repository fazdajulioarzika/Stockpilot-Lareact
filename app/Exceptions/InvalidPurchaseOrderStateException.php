<?php

namespace App\Exceptions;

use DomainException;

class InvalidPurchaseOrderStateException extends DomainException
{
    public function __construct(string $status)
    {
        parent::__construct("Status Purchase Order saat ini ({$status}) tidak mengizinkan tindakan ini.");
    }
}
