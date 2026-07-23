<?php

namespace App\Services\Fbr;

use App\Models\FbrInvoiceSubmission;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FbrDigitalInvoicingService
{
    public function validate(Invoice $invoice): FbrInvoiceSubmission
    {
        return $this->send($invoice, 'validate');
    }

    public function submit(Invoice $invoice): FbrInvoiceSubmission
    {
        return $this->send($invoice, 'submit');
    }

    private function send(Invoice $invoice, string $action): FbrInvoiceSubmission
    {
        $invoice->loadMissing(['company', 'customer.billingAddress', 'items.item', 'items.taxes']);

        $environment = $this->environment();
        $payload = $this->payload($invoice, $environment);
        $url = config("fbr.urls.{$environment}.{$action}");

        $response = Http::withToken($this->token($environment))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('fbr.timeout'))
            ->post($url, $payload);

        return $this->storeSubmission($invoice, $environment, $payload, $response);
    }

    public function payload(Invoice $invoice, string $environment = 'sandbox'): array
    {
        $invoice->loadMissing(['company', 'customer.billingAddress', 'items.item', 'items.taxes']);

        $this->guardConfigured($environment);

        if ($invoice->items->isEmpty()) {
            throw ValidationException::withMessages([
                'invoice' => 'FBR submission requires at least one invoice item.',
            ]);
        }

        $buyerAddress = $invoice->customer?->billingAddress;
        $buyerProvince = $buyerAddress?->state;
        $buyerAddressText = trim(implode(', ', array_filter([
            $buyerAddress?->address_street_1,
            $buyerAddress?->address_street_2,
            $buyerAddress?->city,
        ])));

        $payload = [
            'invoiceType' => 'Sale Invoice',
            'invoiceDate' => Carbon::parse($invoice->invoice_date)->format('Y-m-d'),
            'sellerNTNCNIC' => config('fbr.seller_ntn'),
            'sellerBusinessName' => config('fbr.seller_business_name') ?: $invoice->company->name,
            'sellerProvince' => config('fbr.seller_province'),
            'sellerAddress' => config('fbr.seller_address'),
            'buyerNTNCNIC' => $invoice->customer?->fbr_ntn ?: $invoice->customer?->fbr_cnic ?: $invoice->customer?->tax_id ?: null,
            'buyerBusinessName' => $invoice->customer?->company_name ?: $invoice->customer?->name,
            'buyerProvince' => $buyerProvince,
            'buyerAddress' => $buyerAddressText ?: null,
            'buyerRegistrationType' => $invoice->customer?->fbr_registration_type ?: config('fbr.default_buyer_registration_type'),
            'invoiceRefNo' => $invoice->invoice_number,
            'items' => $invoice->items->map(fn ($item) => $this->itemPayload($item))->values()->all(),
        ];

        if ($environment === 'sandbox' && config('fbr.sandbox_scenario_id')) {
            $payload['scenarioId'] = config('fbr.sandbox_scenario_id');
        }

        $this->guardPayload($payload);

        return $payload;
    }

    private function itemPayload(InvoiceItem $item): array
    {
        $tax = $item->taxes->first();
        $rate = $tax?->percent !== null ? rtrim(rtrim(number_format($tax->percent, 2, '.', ''), '0'), '.').'%' : null;

        $payload = [
            'hsCode' => $item->fbr_hs_code ?: $item->item?->fbr_hs_code ?: config('fbr.default_hs_code'),
            'productDescription' => $item->name,
            'rate' => $rate,
            'uoM' => $item->fbr_uom ?: $item->unit_name ?: $item->item?->fbr_uom ?: config('fbr.default_uom'),
            'quantity' => (float) $item->quantity,
            'totalValues' => $this->money($item->total + $item->tax),
            'valueSalesExcludingST' => $this->money($item->total),
            'salesTaxApplicable' => $this->money($item->tax),
            'saleType' => $item->fbr_sale_type ?: $item->item?->fbr_sale_type ?: config('fbr.default_sale_type'),
            'fixedNotifiedValueOrRetailPrice' => $this->optionalMoney($item->fbr_fixed_notified_value ?? $item->item?->fbr_fixed_notified_value),
            'salesTaxWithheldAtSource' => $this->optionalMoney($item->fbr_sales_tax_withheld ?? $item->item?->fbr_sales_tax_withheld),
            'furtherTax' => $this->optionalMoney($item->fbr_further_tax ?? $item->item?->fbr_further_tax),
            'extraTax' => $this->optionalMoney($item->fbr_extra_tax ?? $item->item?->fbr_extra_tax),
            'fedPayable' => $this->optionalMoney($item->fbr_fed_payable ?? $item->item?->fbr_fed_payable),
            'sroScheduleNo' => $item->fbr_sro_no ?: $item->item?->fbr_sro_no ?: null,
            'sroItemSerialNo' => $item->fbr_sro_item_no ?: $item->item?->fbr_sro_item_no ?: null,
        ];

        return array_filter($payload, fn ($value) => $value !== null);
    }

    private function storeSubmission(Invoice $invoice, string $environment, array $payload, Response $response): FbrInvoiceSubmission
    {
        $responsePayload = $response->json() ?? ['body' => $response->body()];
        $fbrInvoiceNumber = Arr::get($responsePayload, 'invoiceNumber')
            ?? Arr::get($responsePayload, 'InvoiceNumber')
            ?? Arr::get($responsePayload, 'data.invoiceNumber');

        return FbrInvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'environment' => $environment,
            'status' => $response->successful() && $fbrInvoiceNumber ? FbrInvoiceSubmission::STATUS_SUBMITTED : FbrInvoiceSubmission::STATUS_FAILED,
            'fbr_invoice_number' => $fbrInvoiceNumber,
            'request_payload' => $payload,
            'response_payload' => $responsePayload,
            'error_message' => $response->successful() ? null : $response->body(),
            'submitted_at' => now(),
        ]);
    }

    private function guardConfigured(string $environment): void
    {
        if (! config('fbr.enabled')) {
            throw ValidationException::withMessages([
                'fbr' => 'FBR integration is disabled. Set FBR_ENABLED=true and configure FBR credentials.',
            ]);
        }

        $required = [
            'fbr.seller_ntn' => 'FBR_SELLER_NTN',
            'fbr.seller_province' => 'FBR_SELLER_PROVINCE',
            'fbr.seller_address' => 'FBR_SELLER_ADDRESS',
            'fbr.default_hs_code' => 'FBR_DEFAULT_HS_CODE',
            'fbr.default_uom' => 'FBR_DEFAULT_UOM',
            "fbr.{$environment}_token" => strtoupper("FBR_{$environment}_TOKEN"),
        ];

        $missing = collect($required)
            ->filter(fn ($envName, $configKey) => blank(config($configKey)))
            ->values()
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'fbr' => 'Missing FBR configuration: '.implode(', ', $missing),
            ]);
        }
    }

    private function guardPayload(array $payload): void
    {
        $missing = collect([
            'buyerNTNCNIC' => 'Customer tax ID / NTN-CNIC',
            'buyerBusinessName' => 'Customer name',
            'buyerProvince' => 'Customer billing province/state',
            'buyerAddress' => 'Customer billing address',
        ])->filter(fn ($label, $key) => blank($payload[$key]))->values()->all();

        foreach ($payload['items'] as $index => $item) {
            foreach (['hsCode' => 'item HS code', 'rate' => 'item tax rate', 'uoM' => 'item unit/UOM', 'saleType' => 'item sale type'] as $key => $label) {
                if (blank($item[$key])) {
                    $missing[] = 'Line '.($index + 1).' '.$label;
                }
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'fbr' => 'Missing FBR invoice data: '.implode(', ', $missing),
            ]);
        }
    }

    private function token(string $environment): string
    {
        return (string) config("fbr.{$environment}_token");
    }

    private function environment(): string
    {
        return config('fbr.environment') === 'production' ? 'production' : 'sandbox';
    }

    private function money(int $amount): float
    {
        return round($amount / 100, 2);
    }

    private function optionalMoney(?int $amount): ?float
    {
        return $amount === null ? null : $this->money($amount);
    }
}
