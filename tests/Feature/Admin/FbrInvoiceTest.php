<?php

use App\Models\Address;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

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
    config([
        'fbr.sandbox_token' => 'sandbox-token',
        'fbr.enabled' => true,
        'fbr.seller_ntn' => '1234567',
        'fbr.seller_business_name' => 'Seller Pvt Ltd',
        'fbr.seller_province' => 'Sindh',
        'fbr.seller_address' => 'Karachi',
        'fbr.default_hs_code' => '0101.2100',
        'fbr.default_uom' => 'Numbers, pieces, units',
        'fbr.sandbox_scenario_id' => 'SN001',
    ]);

    Http::fake([
        'gw.fbr.gov.pk/*' => Http::response(['invoiceNumber' => 'FBR-001'], 200),
    ]);

    $customer = Customer::factory()->create([
        'company_id' => $this->company->id,
        'tax_id' => 'legacy-tax-id',
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
        'address_street_2' => null,
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-07-23',
        'invoice_number' => 'INV-001',
        'sub_total' => 100000,
        'total' => 118000,
        'tax' => 18000,
        'due_amount' => 118000,
    ]);

    $item = InvoiceItem::factory()->create([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'name' => 'Welding Rod',
        'quantity' => 2,
        'price' => 50000,
        'total' => 100000,
        'tax' => 18000,
        'unit_name' => null,
    ]);

    $taxType = TaxType::factory()->create([
        'company_id' => $this->company->id,
        'percent' => 18,
    ]);

    Tax::factory()->create([
        'company_id' => $this->company->id,
        'invoice_item_id' => $item->id,
        'tax_type_id' => $taxType->id,
        'percent' => 18,
        'amount' => 18000,
    ]);

    postJson("api/v1/invoices/{$invoice->id}/fbr/submit")
        ->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED')
        ->assertJsonPath('data.fbr_invoice_number', 'FBR-001');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer sandbox-token')
            && $request['sellerNTNCNIC'] === '1234567'
            && $request['buyerNTNCNIC'] === '1000000000000'
            && $request['buyerRegistrationType'] === 'Registered'
            && $request['invoiceRefNo'] === 'INV-001'
            && $request['scenarioId'] === 'SN001'
            && $request['items'][0]['rate'] === '18%'
            && $request['items'][0]['valueSalesExcludingST'] === 1000.00
            && $request['items'][0]['salesTaxApplicable'] === 180.00;
    });

    $this->assertDatabaseHas('fbr_invoice_submissions', [
        'invoice_id' => $invoice->id,
        'company_id' => $this->company->id,
        'environment' => 'sandbox',
        'status' => 'SUBMITTED',
        'fbr_invoice_number' => 'FBR-001',
    ]);
});
