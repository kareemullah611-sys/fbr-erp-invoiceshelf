<?php

use App\Models\Address;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FbrInvoiceSubmission;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->company = $user->companies()->first();
    $this->withHeaders([
        'company' => $this->company->id,
    ]);
    Sanctum::actingAs($user, ['*']);
});

test('submits invoice payload to FBR and records response', function () {
    configureFbr();

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-001'], 200),
    ]);

    $invoice = createFbrReadyInvoice($this->company->id);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED')
        ->assertJsonPath('data.fbr_invoice_number', 'FBR-001');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer sandbox-token')
            && $request['sellerNTNCNIC'] === '1234567'
            && $request['buyerNTNCNIC'] === '1000000000000'
            && $request['buyerRegistrationType'] === 'Registered'
            && $request['invoiceRefNo'] === ''
            && $request['scenarioId'] === 'SN001'
            && $request['items'][0]['hsCode'] === '4901.9900'
            && $request['items'][0]['uoM'] === 'Numbers, pieces, units'
            && $request['items'][0]['saleType'] === 'Goods at standard rate (default)'
            && $request['items'][0]['rate'] === '18%'
            && $request['items'][0]['totalValues'] === 1270.00
            && $request['items'][0]['valueSalesExcludingST'] === 1000.00
            && $request['items'][0]['salesTaxApplicable'] === 180.00
            && $request['items'][0]['discount'] === 0.00
            && $request['items'][0]['fixedNotifiedValueOrRetailPrice'] === 1250.00
            && $request['items'][0]['salesTaxWithheldAtSource'] === 10.00
            && $request['items'][0]['furtherTax'] === 20.00
            && $request['items'][0]['extraTax'] === 30.00
            && $request['items'][0]['fedPayable'] === 40.00
            && $request['items'][0]['sroScheduleNo'] === 'SRO-001'
            && $request['items'][0]['sroItemSerialNo'] === '1';
    });

    $this->assertDatabaseHas('fbr_invoice_submissions', [
        'invoice_id' => $invoice->id,
        'company_id' => $this->company->id,
        'environment' => 'sandbox',
        'status' => 'SUBMITTED',
        'fbr_invoice_number' => 'FBR-001',
    ]);
});

test('normalizes FBR NTN and CNIC identifiers before sending', function () {
    configureFbr([
        'fbr.seller_ntn' => '3611395-6',
    ]);

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-NORMALIZED'], 200),
    ]);

    $invoice = createFbrReadyInvoice($this->company->id);
    $invoice->customer->forceFill([
        'fbr_ntn' => '4763637-5',
    ])->save();

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');

    Http::assertSent(fn ($request) => $request['sellerNTNCNIC'] === '3611395'
        && $request['buyerNTNCNIC'] === '4763637');
});

test('calculates FBR item values from document level tax', function () {
    configureFbr();

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-7316'], 200),
    ]);

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'fbr_ntn' => '1000000000000',
        'fbr_registration_type' => 'Registered',
        'company_name' => 'Buyer Pvt Ltd',
    ]);

    Address::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'type' => Address::BILLING_TYPE,
        'state' => 'Punjab',
        'city' => 'Lahore',
        'address_street_1' => 'Main Road',
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-07-30',
        'invoice_number' => 'INV-7316',
        'tax_per_item' => 'NO',
        'discount_per_item' => 'NO',
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'sub_total' => 620000,
        'tax' => 111600,
        'total' => 731600,
        'due_amount' => 731600,
    ]);

    InvoiceItem::factory()->create([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'name' => 'Welding electrodes',
        'quantity' => 20,
        'price' => 31000,
        'total' => 620000,
        'tax' => 0,
        'unit_name' => 'KG',
        'fbr_hs_code' => '8311.1000',
        'fbr_uom' => 'KG',
        'fbr_sale_type' => 'Goods at standard rate (default)',
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'percent' => 18,
    ]);

    Tax::factory()->create([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'tax_type_id' => $taxType->id,
        'percent' => 18,
        'amount' => 111600,
    ]);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');

    Http::assertSent(function ($request) {
        return $request['invoiceRefNo'] === ''
            && $request['items'][0]['hsCode'] === '8311.1000'
            && $request['items'][0]['productDescription'] === 'Welding electrodes'
            && $request['items'][0]['rate'] === '18%'
            && $request['items'][0]['uoM'] === 'KG'
            && $request['items'][0]['quantity'] === 20.0
            && $request['items'][0]['valueSalesExcludingST'] === 6200.00
            && $request['items'][0]['salesTaxApplicable'] === 1116.00
            && $request['items'][0]['totalValues'] === 7316.00
            && $request['items'][0]['discount'] === 0.00;
    });
});

test('checks invoice readiness before FBR submission', function () {
    configureFbr();

    $invoice = createFbrReadyInvoice($this->company->id);

    getJson("api/v1/invoices/{$invoice->id}/fbr/readiness")
        ->assertOk()
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.already_submitted', false)
        ->assertJsonPath('data.missing', []);
});

test('uses company FBR settings before global configuration', function () {
    configureFbr([
        'fbr.enabled' => false,
        'fbr.sandbox_token' => null,
        'fbr.seller_ntn' => null,
        'fbr.seller_business_name' => null,
        'fbr.seller_province' => null,
        'fbr.seller_address' => null,
        'fbr.default_hs_code' => null,
        'fbr.default_uom' => null,
        'fbr.sandbox_scenario_id' => null,
    ]);

    CompanySetting::setSettings([
        'fbr_enabled' => 'true',
        'fbr_environment' => 'sandbox',
        'fbr_sandbox_token' => 'company-sandbox-token',
        'fbr_seller_ntn' => '7654321',
        'fbr_seller_business_name' => 'Company Seller Pvt Ltd',
        'fbr_seller_province' => 'Punjab',
        'fbr_seller_address' => 'Lahore',
        'fbr_default_hs_code' => '0101.2100',
        'fbr_default_uom' => 'Numbers, pieces, units',
        'fbr_sandbox_scenario_id' => 'SN002',
    ], $this->company->id);

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-COMPANY-001'], 200),
    ]);

    $invoice = createFbrReadyInvoice($this->company->id);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer company-sandbox-token')
            && $request['sellerNTNCNIC'] === '7654321'
            && $request['sellerBusinessName'] === 'Company Seller Pvt Ltd'
            && $request['sellerProvince'] === 'Punjab'
            && $request['sellerAddress'] === 'Lahore'
            && $request['scenarioId'] === 'SN002';
    });
});

test('does not use another company FBR settings', function () {
    configureFbr();

    $otherCompany = Company::factory()->create();
    CompanySetting::setSettings([
        'fbr_enabled' => 'true',
        'fbr_sandbox_token' => 'other-company-token',
        'fbr_seller_ntn' => '9999999',
        'fbr_sandbox_scenario_id' => 'SN999',
    ], $otherCompany->id);

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-001'], 200),
    ]);

    $invoice = createFbrReadyInvoice($this->company->id);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer sandbox-token')
            && $request['sellerNTNCNIC'] === '1234567'
            && $request['scenarioId'] === 'SN001';
    });
});

test('reports missing readiness data without calling FBR', function () {
    configureFbr();

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'tax_id' => null,
        'fbr_ntn' => null,
        'fbr_cnic' => null,
        'company_name' => null,
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-07-23',
        'invoice_number' => 'INV-MISSING',
    ]);

    InvoiceItem::factory()->create([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'name' => 'Incomplete item',
        'tax' => 0,
        'unit_name' => null,
        'fbr_hs_code' => null,
        'fbr_uom' => null,
        'fbr_sale_type' => null,
    ]);

    $response = getJson("api/v1/invoices/{$invoice->id}/fbr/readiness")
        ->assertOk()
        ->assertJsonPath('data.ready', false)
        ->assertJsonPath('data.can_submit', false)
        ->assertJsonPath('data.configured', true);

    expect($response->json('data.missing'))->toContain(
        'Customer tax ID / NTN-CNIC',
        'Customer billing province/state',
        'Line 1 item HS code',
        'Line 1 item tax rate',
        'Line 1 item unit/UOM',
    );
});

test('records successful FBR validation separately from final submission', function () {
    configureFbr();

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response([
            'validationResponse' => [
                'status' => 'Valid',
            ],
        ], 200),
    ]);

    $invoice = createFbrReadyInvoice($this->company->id);

    postJson("api/v1/invoices/{$invoice->id}/fbr/validate")
        ->assertCreated()
        ->assertJsonPath('data.status', 'VALIDATED')
        ->assertJsonPath('data.fbr_invoice_number', null)
        ->assertJsonPath('data.request_payload.invoiceRefNo', '');

    $this->assertDatabaseHas('fbr_invoice_submissions', [
        'invoice_id' => $invoice->id,
        'company_id' => $this->company->id,
        'environment' => 'sandbox',
        'status' => 'VALIDATED',
        'fbr_invoice_number' => null,
    ]);
});

test('keeps zero FBR numeric tax fields as numbers', function () {
    configureFbr();

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-BLANK-OPTIONALS'], 200),
    ]);

    $invoice = createFbrReadyInvoice($this->company->id);
    $item = $invoice->items()->first();
    $item->forceFill([
        'fbr_fixed_notified_value' => 0,
        'fbr_sales_tax_withheld' => 0,
        'fbr_further_tax' => 0,
        'fbr_extra_tax' => 0,
        'fbr_fed_payable' => 0,
    ])->save();

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');

    Http::assertSent(function ($request) {
        $item = $request['items'][0];

        return $request['invoiceRefNo'] === ''
            && $item['totalValues'] === 1180.00
            && $item['fixedNotifiedValueOrRetailPrice'] === 0.00
            && $item['salesTaxWithheldAtSource'] === 0.00
            && $item['furtherTax'] === 0.00
            && $item['extraTax'] === 0.00
            && $item['fedPayable'] === 0.00;
    });
});

test('prevents duplicate FBR final submissions', function () {
    configureFbr();

    Http::fake();

    $invoice = createFbrReadyInvoice($this->company->id);

    FbrInvoiceSubmission::create([
        'invoice_id' => $invoice->id,
        'company_id' => $this->company->id,
        'environment' => 'sandbox',
        'status' => FbrInvoiceSubmission::STATUS_SUBMITTED,
        'fbr_invoice_number' => 'FBR-EXISTING',
    ]);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fbr');

    Http::assertNothingSent();
});

test('builds reduced rate payload for end consumer retailer scenarios', function () {
    configureFbr([
        'fbr.sandbox_scenario_id' => 'SN028',
    ]);

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-REDUCED'], 200),
    ]);

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'tax_id' => null,
        'fbr_ntn' => '1000000000000',
        'fbr_registration_type' => 'Registered',
        'company_name' => 'End Consumer',
    ]);

    Address::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'type' => Address::BILLING_TYPE,
        'state' => 'Sindh',
        'city' => 'Karachi',
        'address_street_1' => 'Main Road',
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-08-05',
        'invoice_number' => 'INV-REDUCED',
        'tax_per_item' => 'YES',
        'discount_per_item' => 'NO',
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'sub_total' => 10000,
        'tax' => 100,
        'total' => 10100,
        'due_amount' => 10100,
    ]);

    $item = InvoiceItem::factory()->create([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'name' => 'Baby formula',
        'quantity' => 1,
        'price' => 10000,
        'total' => 10000,
        'tax' => 100,
        'unit_name' => null,
        'fbr_hs_code' => '0101.2100',
        'fbr_uom' => 'Numbers, pieces, units',
        'fbr_sale_type' => 'goods at reduced rate',
        'fbr_sro_no' => null,
        'fbr_sro_item_no' => '70',
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'percent' => 1,
    ]);

    Tax::factory()->create([
        'company_id' => $this->company->id,
        'invoice_item_id' => $item->id,
        'tax_type_id' => $taxType->id,
        'percent' => 1,
        'amount' => 100,
    ]);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');

    Http::assertSent(function ($request) {
        $item = $request['items'][0];

        return $request['scenarioId'] === 'SN028'
            && $request['buyerRegistrationType'] === 'Registered'
            && $item['saleType'] === 'Goods at Reduced Rate'
            && $item['rate'] === '1%'
            && $item['sroScheduleNo'] === 'EIGHTH SCHEDULE Table 1'
            && $item['sroItemSerialNo'] === '70'
            && $item['extraTax'] === ''
            && $item['valueSalesExcludingST'] === 100.00
            && $item['salesTaxApplicable'] === 1.00
            && $item['totalValues'] === 101.00;
    });
});

test('emits FBR item payload fields in the official template order', function () {
    configureFbr([
        'fbr.sandbox_scenario_id' => 'SN028',
    ]);

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-ORDER'], 200),
    ]);

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'tax_id' => null,
        'fbr_ntn' => '1000000000000',
        'fbr_registration_type' => 'Registered',
        'company_name' => 'End Consumer',
    ]);

    Address::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'type' => Address::BILLING_TYPE,
        'state' => 'Sindh',
        'city' => 'Karachi',
        'address_street_1' => 'Main Road',
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-08-05',
        'invoice_number' => 'INV-ORDER',
        'tax_per_item' => 'YES',
        'discount_per_item' => 'NO',
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'sub_total' => 10000,
        'tax' => 100,
        'total' => 10100,
        'due_amount' => 10100,
    ]);

    $item = InvoiceItem::factory()->create([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'name' => 'Reduced rate item',
        'quantity' => 1,
        'price' => 10000,
        'total' => 10000,
        'tax' => 100,
        'unit_name' => null,
        'fbr_hs_code' => '0101.2100',
        'fbr_uom' => 'Numbers, pieces, units',
        'fbr_sale_type' => 'goods at reduced rate',
        'fbr_sro_no' => null,
        'fbr_sro_item_no' => '70',
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'percent' => 1,
    ]);

    Tax::factory()->create([
        'company_id' => $this->company->id,
        'invoice_item_id' => $item->id,
        'tax_type_id' => $taxType->id,
        'percent' => 1,
        'amount' => 100,
    ]);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $item = $payload['items'][0];

        $expectedOrder = [
            'hsCode',
            'productDescription',
            'rate',
            'uoM',
            'quantity',
            'totalValues',
            'valueSalesExcludingST',
            'fixedNotifiedValueOrRetailPrice',
            'salesTaxApplicable',
            'salesTaxWithheldAtSource',
            'extraTax',
            'furtherTax',
            'sroScheduleNo',
            'fedPayable',
            'discount',
            'saleType',
            'sroItemSerialNo',
        ];

        $invoiceKeys = array_keys($payload);

        return array_keys($item) === $expectedOrder
            && strpos(implode(',', $invoiceKeys), 'scenarioId') < strpos(implode(',', $invoiceKeys), 'items')
            && $item['extraTax'] === ''
            && $item['saleType'] === 'Goods at Reduced Rate'
            && $item['sroScheduleNo'] === 'EIGHTH SCHEDULE Table 1'
            && $item['sroItemSerialNo'] === '70';
    });
});

test('bootstrap fbr_reference reflects per-company scenarios and reduced-rate catalog', function () {
    CompanySetting::setSettings([
        'fbr_scenarios' => json_encode([
            'SN900' => 'Custom Reduced Rate',
            'SN901' => 'Custom Retailer',
        ]),
        'fbr_reduced_rate_hs' => json_encode([
            '0102.2930' => [
                'rate' => '10%',
                'sroScheduleNo' => 'EIGHTH SCHEDULE Table 1',
                'sroItemSerialNo' => '84(i)',
            ],
        ]),
    ], $this->company->id);

    $response = getJson('api/v1/bootstrap')->assertOk();

    $reference = $response->json('fbr_reference');

    expect($reference['scenarios'])->toBe([
        'SN900' => 'Custom Reduced Rate',
        'SN901' => 'Custom Retailer',
    ])
        ->and($reference['reduced_rate_hs'])->toBe([
            '0102.2930' => [
                'rate' => '10%',
                'sroScheduleNo' => 'EIGHTH SCHEDULE Table 1',
                'sroItemSerialNo' => '84(i)',
            ],
        ]);
});

function configureFbr(array $overrides = []): void
{
    config([
        ...[
            'fbr.sandbox_token' => 'sandbox-token',
            'fbr.enabled' => true,
            'fbr.seller_ntn' => '1234567',
            'fbr.seller_business_name' => 'Seller Pvt Ltd',
            'fbr.seller_province' => 'Sindh',
            'fbr.seller_address' => 'Karachi',
            'fbr.default_hs_code' => '0101.2100',
            'fbr.default_uom' => 'Numbers, pieces, units',
            'fbr.sandbox_scenario_id' => 'SN001',
        ],
        ...$overrides,
    ]);
}

function createFbrReadyInvoice(int $companyId): Invoice
{
    $customer = Customer::factory()->create([
        'company_id' => $companyId,
        'tax_id' => 'legacy-tax-id',
        'fbr_ntn' => '1000000000000',
        'fbr_registration_type' => 'Registered',
        'company_name' => 'Buyer Pvt Ltd',
    ]);

    Address::factory()->create([
        'company_id' => $companyId,
        'customer_id' => $customer->id,
        'type' => Address::BILLING_TYPE,
        'state' => 'Punjab',
        'city' => 'Lahore',
        'address_street_1' => 'Main Road',
        'address_street_2' => null,
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $companyId,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-07-23',
        'invoice_number' => 'INV-001',
        'discount_type' => 'fixed',
        'discount' => 0,
        'discount_val' => 0,
        'sub_total' => 100000,
        'total' => 118000,
        'tax' => 18000,
        'due_amount' => 118000,
    ]);

    $item = InvoiceItem::factory()->create([
        'company_id' => $companyId,
        'invoice_id' => $invoice->id,
        'name' => 'Welding Rod',
        'quantity' => 2,
        'price' => 50000,
        'total' => 100000,
        'tax' => 18000,
        'unit_name' => null,
        'fbr_hs_code' => '4901.9900',
        'fbr_uom' => 'Numbers, pieces, units',
        'fbr_sale_type' => 'Goods at standard rate (default)',
        'fbr_sro_no' => 'SRO-001',
        'fbr_sro_item_no' => '1',
        'fbr_fixed_notified_value' => 125000,
        'fbr_sales_tax_withheld' => 1000,
        'fbr_further_tax' => 2000,
        'fbr_extra_tax' => 3000,
        'fbr_fed_payable' => 4000,
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $companyId,
        'percent' => 18,
    ]);

    Tax::factory()->create([
        'company_id' => $companyId,
        'invoice_item_id' => $item->id,
        'tax_type_id' => $taxType->id,
        'percent' => 18,
        'amount' => 18000,
    ]);

    return $invoice;
}
