

<?php

use Illuminate\Foundation\Http\FormRequest;

class FundWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:100'],
            'payment_method' => ['required', 'string', 'in:paystack,flutterwave,bank_transfer'],
            'metadata' => ['sometimes', 'array']
        ];
    }
}
?>

