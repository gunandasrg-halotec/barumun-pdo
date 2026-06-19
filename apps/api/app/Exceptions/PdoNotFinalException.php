<?php

namespace App\Exceptions;

use RuntimeException;

// BR-CLOSE-001: PDO harus berstatus 'final' sebelum bisa ditutup
class PdoNotFinalException extends RuntimeException
{
    public function __construct(string $currentStatus)
    {
        parent::__construct("PDO tidak bisa ditutup karena statusnya '{$currentStatus}', bukan 'final'.");
    }
}
