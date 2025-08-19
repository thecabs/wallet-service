<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreditWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth via middleware
    }

    public function rules(): array
    {
        return [
            'amount'      => 'required|numeric|min:0.01|max:999999999.99',
            'reference'   => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'period'      => 'nullable|in:daily,weekly,monthly',
        ];
    }
}
