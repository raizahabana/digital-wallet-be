<?php

namespace App\Exceptions;

class CurrencyMismatchException extends ProblemException
{
    public function __construct(string $message = 'Transfers are only allowed between wallets with the same currency.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Currency mismatch';
    }
}
