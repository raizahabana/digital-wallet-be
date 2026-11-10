<?php

namespace App\Exceptions;

class RecipientNotFoundException extends ProblemException
{
    public function __construct(string $message = 'No wallet found for that recipient.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 404;
    }

    public function title(): string
    {
        return 'Recipient not found';
    }
}