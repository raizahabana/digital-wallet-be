<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesAmount;
use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    use ValidatesAmount;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_email' => ['required', 'email'],
            'amount' => $this->amountRules(),
        ];
    }
}