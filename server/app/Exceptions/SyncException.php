<?php

namespace App\Exceptions;

class SyncException extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 409)
    {
        parent::__construct($message);
    }
}
