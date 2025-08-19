<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class DebitWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:255|unique:wallet_transactions,reference',
            'description' => 'nullable|string|max:255',
        ];
    }
}