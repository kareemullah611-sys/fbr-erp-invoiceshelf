<?php

namespace App\Services\Fbr;

use App\Models\CompanySetting;
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
    private array $companySettings = [];

    public function validate(Invoice $invoice): FbrInvoiceSubmission
    {
        if ($this->environment($invoice) === 'production') {
            throw ValidationException::withMessages([
                'fbr' => 'FBR validation is available only in sandbox. Production IRIS provides the final post invoice API only; use Submit to FBR for production invoices.',
            ]);
        }

        return $this->send($invoice, 'validate');
    }

    public function submit(Invoice $invoice): FbrInvoiceSubmission
    {
        if ($invoice->fbrSubmissions()->where('status', FbrInvoiceSubmission::STATUS_SUBMITTED)->exists()) {
            throw ValidationException::withMessages([
                'fbr' => 'This invoice has already been submitted to FBR.',
            ]);
        }

        return $this->send($invoice, 'submit');
    }

    public function readiness(Invoice $invoice): array
    {
        $invoice->loadMissing('company');

        $environment = $this->environment($invoice);
        $missing = $this->configurationMissing($invoice, $environment);
        $alreadySubmitted = $invoice->fbrSubmissions()
            ->where('status', FbrInvoiceSubmission::STATUS_SUBMITTED)
            ->exists();

        if ($alreadySubmitted) {
            $missing[] = 'Invoice already has a submitted FBR invoice number.';
        }

        try {
            $payload = $this->buildPayload($invoice, $environment);
            $missing = array_values(array_unique([
                ...$missing,
                ...$this->payloadMissing($payload),
            ]));
        } catch (ValidationException $exception) {
            $missing = array_values(array_unique([
                ...$missing,
                ...collect($exception->errors())->flatten()->all(),
            ]));
        }

        return [
            'ready' => $missing === [],
            'can_submit' => $missing === [],
            'environment' => $environment,
            'configured' => $this->configurationMissing($invoice, $environment) === [],
            'already_submitted' => $alreadySubmitted,
            'missing' => $missing,
        ];
    }

    private function send(Invoice $invoice, string $action): FbrInvoiceSubmission
    {
        $invoice->loadMissing(['company', 'customer.billingAddress', 'items.item', 'items.taxes', 'taxes']);

        $environment = $this->environment($invoice);
        $payload = $this->payload($invoice, $environment);
        $url = config("fbr.urls.{$environment}.{$action}");

        if (blank($url)) {
            throw ValidationException::withMessages([
                'fbr' => "FBR {$action} URL is not configured for {$environment}.",
            ]);
        }

        $response = Http::withToken($this->token($invoice, $environment))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('fbr.timeout'))
            ->post($url, $payload);

        return $this->storeSubmission($invoice, $environment, $action, $payload, $response);
    }

    public function payload(Invoice $invoice, string $environment = 'sandbox'): array
    {
        $invoice->loadMissing('company');

        $this->guardConfigured($invoice, $environment);

        $payload = $this->buildPayload($invoice, $environment);

        $this->guardPayload($payload);

        return $payload;
    }

    private function buildPayload(Invoice $invoice, string $environment): array
    {
        $invoice->loadMissing(['company', 'customer.billingAddress', 'items.item', 'items.taxes', 'taxes']);

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
            'sellerNTNCNIC' => $this->taxIdentifier($this->setting($invoice, 'seller_ntn')),
            'sellerBusinessName' => $this->setting($invoice, 'seller_business_name') ?: $invoice->company->name,
            'sellerProvince' => $this->setting($invoice, 'seller_province'),
            'sellerAddress' => $this->setting($invoice, 'seller_address'),
            'buyerNTNCNIC' => $this->taxIdentifier($invoice->customer?->fbr_ntn ?: $invoice->customer?->fbr_cnic ?: $invoice->customer?->tax_id ?: null),
            'buyerBusinessName' => $invoice->customer?->company_name ?: $invoice->customer?->name,
            'buyerProvince' => $buyerProvince,
            'buyerAddress' => $buyerAddressText ?: null,
            'buyerRegistrationType' => $invoice->customer?->fbr_registration_type ?: $this->setting($invoice, 'default_buyer_registration_type'),
            'invoiceRefNo' => $invoice->invoice_number,
            'items' => $invoice->items->map(fn ($item) => $this->itemPayload($invoice, $item))->values()->all(),
        ];

        if ($environment === 'sandbox' && $this->setting($invoice, 'sandbox_scenario_id')) {
            $payload['scenarioId'] = $this->setting($invoice, 'sandbox_scenario_id');
        }

        return $payload;
    }

    private function itemPayload(Invoice $invoice, InvoiceItem $item): array
    {
        $tax = $item->taxes->first() ?: $invoice->taxes->first();
        $rate = $tax?->percent !== null ? rtrim(rtrim(number_format($tax->percent, 2, '.', ''), '0'), '.').'%' : null;
        $discount = $this->lineDiscount($invoice, $item);
        $valueSalesExcludingST = max(0, $item->total - $discount);
        $salesTaxApplicable = $this->lineSalesTax($invoice, $item, $valueSalesExcludingST, $tax?->percent);
        $furtherTax = $this->optionalMinorAmount($item->fbr_further_tax ?? $item->item?->fbr_further_tax);
        $extraTax = $this->optionalMinorAmount($item->fbr_extra_tax ?? $item->item?->fbr_extra_tax);
        $fedPayable = $this->optionalMinorAmount($item->fbr_fed_payable ?? $item->item?->fbr_fed_payable);

        $payload = [
            'hsCode' => $item->fbr_hs_code ?: $item->item?->fbr_hs_code,
            'productDescription' => $item->name,
            'rate' => $rate,
            'uoM' => $item->fbr_uom ?: $item->unit_name ?: $item->item?->fbr_uom,
            'quantity' => (float) $item->quantity,
            'totalValues' => $this->money($valueSalesExcludingST + $salesTaxApplicable + ($furtherTax ?? 0) + ($extraTax ?? 0) + ($fedPayable ?? 0)),
            'valueSalesExcludingST' => $this->money($valueSalesExcludingST),
            'salesTaxApplicable' => $this->money($salesTaxApplicable),
            'discount' => $this->money($discount),
            'saleType' => $item->fbr_sale_type ?: $item->item?->fbr_sale_type,
            'fixedNotifiedValueOrRetailPrice' => $this->requiredMoney($item->fbr_fixed_notified_value ?? $item->item?->fbr_fixed_notified_value),
            'salesTaxWithheldAtSource' => $this->requiredMoney($item->fbr_sales_tax_withheld ?? $item->item?->fbr_sales_tax_withheld),
            'furtherTax' => $this->optionalMoney($furtherTax),
            'extraTax' => $this->optionalMoney($extraTax),
            'fedPayable' => $this->optionalMoney($fedPayable),
            'sroScheduleNo' => $item->fbr_sro_no ?: $item->item?->fbr_sro_no ?: null,
            'sroItemSerialNo' => $item->fbr_sro_item_no ?: $item->item?->fbr_sro_item_no ?: null,
        ];

        foreach ($this->optionalFbrAmountFields() as $field) {
            if (! isset($payload[$field]) || ! is_numeric($payload[$field]) || (float) $payload[$field] <= 0) {
                unset($payload[$field]);
            }
        }

        return array_filter($payload, fn ($value) => $value !== null);
    }

    private function lineDiscount(Invoice $invoice, InvoiceItem $item): int
    {
        if (strtoupper((string) $invoice->discount_per_item) === 'YES') {
            return (int) $item->discount_val;
        }

        if ($invoice->discount_val <= 0 || $invoice->sub_total <= 0) {
            return 0;
        }

        return (int) round(((int) $item->total / (int) $invoice->sub_total) * (int) $invoice->discount_val);
    }

    private function lineSalesTax(Invoice $invoice, InvoiceItem $item, int $valueSalesExcludingST, ?float $percent): int
    {
        if ((int) $item->tax > 0) {
            return (int) $item->tax;
        }

        if ($percent !== null) {
            return (int) round($valueSalesExcludingST * ($percent / 100));
        }

        if ($invoice->tax <= 0 || $invoice->sub_total <= 0) {
            return 0;
        }

        return (int) round(($valueSalesExcludingST / (int) $invoice->sub_total) * (int) $invoice->tax);
    }

    private function storeSubmission(Invoice $invoice, string $environment, string $action, array $payload, Response $response): FbrInvoiceSubmission
    {
        $responsePayload = $response->json() ?? ['body' => $response->body()];
        $fbrInvoiceNumber = Arr::get($responsePayload, 'invoiceNumber')
            ?? Arr::get($responsePayload, 'InvoiceNumber')
            ?? Arr::get($responsePayload, 'data.invoiceNumber');
        $status = $this->submissionStatus($action, $response, $responsePayload, $fbrInvoiceNumber);

        return FbrInvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'environment' => $environment,
            'status' => $status,
            'fbr_invoice_number' => $fbrInvoiceNumber,
            'request_payload' => $payload,
            'response_payload' => $responsePayload,
            'error_message' => $status === FbrInvoiceSubmission::STATUS_FAILED ? $this->errorMessage($response, $responsePayload) : null,
            'submitted_at' => now(),
        ]);
    }

    private function guardConfigured(Invoice $invoice, string $environment): void
    {
        if (! $this->enabled($invoice)) {
            throw ValidationException::withMessages([
                'fbr' => 'FBR integration is disabled. Set FBR_ENABLED=true and configure FBR credentials.',
            ]);
        }

        $missing = $this->configurationMissing($invoice, $environment);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'fbr' => 'Missing FBR configuration: '.implode(', ', $missing),
            ]);
        }
    }

    private function guardPayload(array $payload): void
    {
        $missing = $this->payloadMissing($payload);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'fbr' => 'Missing FBR invoice data: '.implode(', ', $missing),
            ]);
        }
    }

    private function payloadMissing(array $payload): array
    {
        $missing = collect([
            'buyerNTNCNIC' => 'Customer tax ID / NTN-CNIC',
            'buyerBusinessName' => 'Customer name',
            'buyerProvince' => 'Customer billing province/state',
            'buyerAddress' => 'Customer billing address',
        ])->filter(fn ($label, $key) => blank($payload[$key] ?? null))->values()->all();

        foreach ($payload['items'] as $index => $item) {
            foreach (['hsCode' => 'item HS code', 'rate' => 'item tax rate', 'uoM' => 'item unit/UOM', 'saleType' => 'item sale type'] as $key => $label) {
                if (blank($item[$key] ?? null)) {
                    $missing[] = 'Line '.($index + 1).' '.$label;
                }
            }
        }

        return $missing;
    }

    private function configurationMissing(Invoice $invoice, string $environment): array
    {
        $required = [
            'seller_ntn' => 'FBR_SELLER_NTN',
            'seller_province' => 'FBR_SELLER_PROVINCE',
            'seller_address' => 'FBR_SELLER_ADDRESS',
            "{$environment}_token" => strtoupper("FBR_{$environment}_TOKEN"),
        ];

        if (! $this->enabled($invoice)) {
            $required['enabled'] = 'FBR_ENABLED';
        }

        return collect($required)
            ->filter(fn ($envName, $key) => $key === 'enabled'
                ? ! $this->enabled($invoice)
                : blank($this->setting($invoice, $key)))
            ->values()
            ->all();
    }

    private function submissionStatus(string $action, Response $response, array $responsePayload, ?string $fbrInvoiceNumber): string
    {
        if (! $response->successful()) {
            return FbrInvoiceSubmission::STATUS_FAILED;
        }

        if ($action === 'validate' && $this->responseIsValid($responsePayload)) {
            return FbrInvoiceSubmission::STATUS_VALIDATED;
        }

        return $fbrInvoiceNumber ? FbrInvoiceSubmission::STATUS_SUBMITTED : FbrInvoiceSubmission::STATUS_FAILED;
    }

    private function responseIsValid(array $responsePayload): bool
    {
        $status = Arr::get($responsePayload, 'validationResponse.status')
            ?? Arr::get($responsePayload, 'ValidationResponse.status')
            ?? Arr::get($responsePayload, 'status')
            ?? Arr::get($responsePayload, 'data.status');

        return is_string($status) && in_array(strtolower($status), ['valid', 'validated'], true);
    }

    private function errorMessage(Response $response, array $responsePayload): string
    {
        foreach ([
            'validationResponse.error',
            'validationResponse.message',
            'validationResponse.errors',
            'validationResponse.invoiceStatuses.0.error',
            'validationResponse.invoiceStatuses.0.errorCode',
            'ValidationResponse.error',
            'ValidationResponse.message',
            'ValidationResponse.errors',
            'error',
            'message',
            'errors',
            'data.error',
            'data.message',
            'data.errors',
        ] as $key) {
            $message = $this->stringifyError(Arr::get($responsePayload, $key));

            if (filled($message)) {
                return $message;
            }
        }

        $message = $this->firstErrorMessage($responsePayload);

        return filled($message)
            ? $message
            : (trim($response->body()) ?: "FBR request failed with HTTP {$response->status()}.");
    }

    private function firstErrorMessage(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['error', 'message', 'errors', 'errorMessage', 'error_message'] as $key) {
            if (array_key_exists($key, $value)) {
                $message = $this->stringifyError($value[$key]);

                if (filled($message)) {
                    return $message;
                }
            }
        }

        foreach ($value as $nestedValue) {
            $message = $this->firstErrorMessage($nestedValue);

            if (filled($message)) {
                return $message;
            }
        }

        return null;
    }

    private function stringifyError(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return null;
        }

        return collect($value)
            ->flatMap(fn ($item) => is_array($item) ? collect($item)->flatten() : [$item])
            ->filter(fn ($item) => is_string($item) || is_numeric($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->implode(' ');
    }

    private function token(Invoice $invoice, string $environment): string
    {
        return (string) $this->setting($invoice, "{$environment}_token");
    }

    private function environment(Invoice $invoice): string
    {
        return $this->setting($invoice, 'environment') === 'production' ? 'production' : 'sandbox';
    }

    private function enabled(Invoice $invoice): bool
    {
        $value = CompanySetting::getSetting('fbr_enabled', $invoice->company_id);

        if ($value === null) {
            return (bool) config('fbr.enabled');
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function setting(Invoice $invoice, string $key): mixed
    {
        $this->companySettings[$invoice->company_id] ??= CompanySetting::getAllSettings($invoice->company_id);

        $value = $this->companySettings[$invoice->company_id]->get("fbr_{$key}");

        return blank($value) ? config("fbr.{$key}") : $value;
    }

    private function money(int $amount): float
    {
        return round($amount / 100, 2);
    }

    private function optionalMoney(int|float|string|null $amount): ?float
    {
        $amount = $this->optionalMinorAmount($amount);

        return $amount === null ? null : $this->money($amount);
    }

    private function requiredMoney(int|float|string|null $amount): float
    {
        if ($amount === null || $amount === '') {
            return 0.00;
        }

        if (is_string($amount)) {
            $amount = trim($amount);
        }

        if (! is_numeric($amount)) {
            return 0.00;
        }

        return $this->money(max(0, (int) $amount));
    }

    private function optionalFbrAmountFields(): array
    {
        return [
            'furtherTax',
            'extraTax',
            'fedPayable',
        ];
    }

    private function taxIdentifier(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 8 ? substr($digits, 0, 7) : $digits;
    }

    private function optionalMinorAmount(int|float|string|null $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (is_string($amount)) {
            $amount = trim($amount);

            if ($amount === '') {
                return null;
            }
        }

        if (! is_numeric($amount)) {
            return null;
        }

        $amount = (int) $amount;

        return $amount > 0 ? $amount : null;
    }
}
