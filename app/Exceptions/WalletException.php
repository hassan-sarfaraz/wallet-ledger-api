<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class WalletException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $error, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
