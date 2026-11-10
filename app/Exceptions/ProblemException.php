<?php

namespace App\Exceptions;

use RuntimeException;

abstract class ProblemException extends RuntimeException
{
    abstract public function status(): int;

    abstract public function title(): string;
}