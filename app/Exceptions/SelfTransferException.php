<?php

namespace App\Exceptions;

class SelfTransferException extends ProblemException
{
    public function __construct(string $message = 'You cannot transfer funds to yourself.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Invalid transfer';
    }
}