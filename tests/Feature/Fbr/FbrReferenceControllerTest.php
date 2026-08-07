<?php

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function () {
    $user = User::factory()->create();
    $this->company = Company::factory()->create(['owner_id' => $user->id]);
    $user->companies()->attach($this->company->id);

    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($user, ['*']);
});

afterEach(function () {
    File::delete(storage_path('app/fbr/reference-sandbox.json'));
    File::delete(storage_path('app/fbr/reference-production.json'));
});

test('searches hs codes from the imported reference dataset', function () {
    Storage::disk('local')->put('fbr/reference-sandbox.json', json_encode([
        'generated_at' => '2026-01-01T00:00:00Z',
        'hs_codes' => [
            '0101.2100' => 'HORSES',
            '0102.2930' => 'LIVE CATTLE',
        ],
        'hs_uoms' => [
            '0101.2100' => ['Numbers, pieces, units'],
        ],
        'uoms' => [
            ['uoM_ID' => 1, 'description' => 'Numbers, pieces, units'],
            ['uoM_ID' => 13, 'description' => 'KG'],
        ],
    ]));

    getJson('api/v1/fbr/reference/hs-codes?search=HOR')
        ->assertOk()
        ->assertJsonPath('data.0.hs_code', '0101.2100')
        ->assertJsonPath('data.0.description', 'HORSES')
        ->assertJsonPath('data.0.uoms', ['Numbers, pieces, units']);

    getJson('api/v1/fbr/reference/hs-codes?search=0102')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.hs_code', '0102.2930');
});

test('returns all hs codes when no search term is given', function () {
    Storage::disk('local')->put('fbr/reference-sandbox.json', json_encode([
        'generated_at' => null,
        'hs_codes' => ['0101.2100' => 'HORSES', '0102.2930' => 'LIVE CATTLE'],
        'hs_uoms' => [],
        'uoms' => [],
    ]));

    getJson('api/v1/fbr/reference/hs-codes')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('returns unique uom descriptions', function () {
    Storage::disk('local')->put('fbr/reference-sandbox.json', json_encode([
        'generated_at' => null,
        'hs_codes' => [],
        'hs_uoms' => [],
        'uoms' => [
            ['uoM_ID' => 1, 'description' => 'Numbers, pieces, units'],
            ['uoM_ID' => 2, 'description' => 'Numbers, pieces, units'],
            ['uoM_ID' => 13, 'description' => 'KG'],
        ],
    ]));

    getJson('api/v1/fbr/reference/uoms')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', 'Numbers, pieces, units')
        ->assertJsonPath('data.1', 'KG');
});

test('returns empty dataset when reference file is missing', function () {
    getJson('api/v1/fbr/reference/hs-codes')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    getJson('api/v1/fbr/reference/uoms')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('reads from production reference file for production companies', function () {
    CompanySetting::setSettings(['fbr_environment' => 'production'], $this->company->id);

    Storage::disk('local')->put('fbr/reference-sandbox.json', json_encode([
        'generated_at' => null,
        'hs_codes' => ['0101.2100' => 'HORSES'],
        'hs_uoms' => [],
        'uoms' => [],
    ]));

    Storage::disk('local')->put('fbr/reference-production.json', json_encode([
        'generated_at' => null,
        'hs_codes' => ['0201.3000' => 'BEEF'],
        'hs_uoms' => [],
        'uoms' => [],
    ]));

    getJson('api/v1/fbr/reference/hs-codes')
        ->assertOk()
        ->assertJsonPath('data.0.hs_code', '0201.3000');
});
