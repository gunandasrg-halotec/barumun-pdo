<?php

namespace App\Exceptions;

use RuntimeException;

// BR-CLOSE-003: PDO yang sudah closed tidak bisa diubah
class PdoIsClosedException extends RuntimeException
{
    public function __construct(string $closedAt)
    {
        parent::__construct("PDO ini sudah ditutup pada {$closedAt} dan tidak dapat diubah.");
    }
}
