<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth déjà gérée par tes middlewares
        return true;
    }

    public function rules(): array
    {
        return [
            // on tolère devise OU currency ; les deux sont facultatifs car on met 'XAF' par défaut côté controller
            'devise'   => 'nullable|string|size:3',
            'currency' => 'nullable|string|size:3',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalise devise/currency en majuscules
        $cur = $this->input('currency') ?: $this->input('devise');
        if ($cur) {
            $cur = strtoupper((string) $cur);
            $this->merge([
                'currency' => $cur,
                'devise'   => $cur,
            ]);
        }
    }
}
