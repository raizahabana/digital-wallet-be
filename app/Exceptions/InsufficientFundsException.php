<?php // app/Exceptions/InsufficientFundsException.php

namespace App\Exceptions;

class InsufficientFundsException extends ProblemException
{
    public function __construct(string $message = 'Insufficient wallet balance.')
    {
        parent::__construct($message);
    }

    public function status(): int { return 422; }

    public function title(): string { return 'Insufficient funds'; }
}