<?php

namespace App\Http\Requests\Api\V1\Concerns;

trait ValidatesAmount
{
    /** @return array<int, string> */
    protected function amountRules(): array
    {
        // Positive, max 2 decimals, fits decimal(15,2)
        return ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];
    }
}