<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'unit_id' => $this->unit_id,
            'company_id' => $this->company_id,
            'creator_id' => $this->creator_id,
            'currency_id' => $this->currency_id,
            'fbr_hs_code' => $this->fbr_hs_code,
            'fbr_uom' => $this->fbr_uom,
            'fbr_sale_type' => $this->fbr_sale_type,
            'fbr_sro_no' => $this->fbr_sro_no,
            'fbr_sro_item_no' => $this->fbr_sro_item_no,
            'fbr_fixed_notified_value' => $this->fbr_fixed_notified_value,
            'fbr_sales_tax_withheld' => $this->fbr_sales_tax_withheld,
            'fbr_further_tax' => $this->fbr_further_tax,
            'fbr_extra_tax' => $this->fbr_extra_tax,
            'fbr_fed_payable' => $this->fbr_fed_payable,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'tax_per_item' => $this->tax_per_item,
            'formatted_created_at' => $this->formattedCreatedAt,
            'unit' => $this->when($this->unit()->exists(), function () {
                return new UnitResource($this->unit);
            }),
            'company' => $this->when($this->company()->exists(), function () {
                return new CompanyResource($this->company);
            }),
            'taxes' => $this->when($this->taxes()->exists(), function () {
                return TaxResource::collection($this->taxes);
            }),
            'currency' => $this->when($this->currency()->exists(), function () {
                return new CurrencyResource($this->currency);
            }),
        ];
    }
}
