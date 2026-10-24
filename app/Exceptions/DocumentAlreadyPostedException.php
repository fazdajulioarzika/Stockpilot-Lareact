<?php

namespace App\Exceptions;

use DomainException;

class DocumentAlreadyPostedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Dokumen ini sudah diposting atau tidak dalam status draft.');
    }
}
