<?php

namespace App\Exceptions;

use DomainException;

class InvalidTransferStateException extends DomainException
{
    public function __construct(string $status)
    {
        parent::__construct("Status transfer saat ini ({$status}) tidak mengizinkan tindakan ini.");
    }
}
