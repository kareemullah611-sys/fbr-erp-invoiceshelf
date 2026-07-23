<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
            ],
            'price' => [
                'required',
            ],
            'unit_id' => [
                'nullable',
            ],
            'description' => [
                'nullable',
            ],
            'fbr_hs_code' => [
                'nullable',
                'string',
                'max:255',
            ],
            'fbr_uom' => [
                'nullable',
                'string',
                'max:255',
            ],
            'fbr_sale_type' => [
                'nullable',
                'string',
                'max:255',
            ],
            'fbr_sro_no' => [
                'nullable',
                'string',
                'max:255',
            ],
            'fbr_sro_item_no' => [
                'nullable',
                'string',
                'max:255',
            ],
            'fbr_fixed_notified_value' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'fbr_sales_tax_withheld' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'fbr_further_tax' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'fbr_extra_tax' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'fbr_fed_payable' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ];
    }
}
