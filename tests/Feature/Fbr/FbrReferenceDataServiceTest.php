<?php

use App\Services\Fbr\FbrReferenceDataService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['fbr.sandbox_token' => 'sandbox-token']);
});

test('resolves token from explicit value over config', function () {
    $service = app(FbrReferenceDataService::class);

    expect($service->resolveToken('explicit-token'))->toBe('explicit-token');
    expect($service->resolveToken(null))->toBe('sandbox-token');
    expect($service->resolveToken(null, 'production'))->toBeNull();
});

test('fetches hs codes and uoms from fbr reference apis', function () {
    Http::fake([
        'gw.fbr.gov.pk/pdi/v1/itemdesccode' => Http::response([
            ['hS_CODE' => '0101.2100', 'description' => 'HORSES'],
            ['hS_CODE' => '0102.2930', 'description' => 'LIVE CATTLE'],
        ]),
        'gw.fbr.gov.pk/pdi/v1/uom' => Http::response([
            ['uoM_ID' => 77, 'description' => 'Square Metre'],
            ['uoM_ID' => 13, 'description' => 'KG'],
        ]),
        'gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response([
            ['uoM_ID' => 13, 'description' => 'KG'],
        ]),
    ]);

    $service = app(FbrReferenceDataService::class);

    $dataset = $service->referenceDataset('sandbox-token');

    expect($dataset['hs_codes'])->toBe([
        '0101.2100' => 'HORSES',
        '0102.2930' => 'LIVE CATTLE',
    ])
        ->and($dataset['uoms'])->toBe([
            ['uoM_ID' => 77, 'description' => 'Square Metre'],
            ['uoM_ID' => 13, 'description' => 'KG'],
        ])
        ->and($dataset['hs_uoms']['0101.2100'])->toBe(['KG']);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/pdi/v2/HS_UOM')
            && $request['hs_code'] === '0101.2100'
            && $request['annexure_id'] === 1;
    });
});

test('tries annexure ids until uom mapping is found', function () {
    Http::fake([
        'gw.fbr.gov.pk/pdi/v1/itemdesccode' => Http::response([
            ['hS_CODE' => '5904.9000', 'description' => 'TEXTILE'],
        ]),
        'gw.fbr.gov.pk/pdi/v1/uom' => Http::response([]),
        'gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::sequence()
            ->push([], 200)
            ->push([], 200)
            ->push([['uoM_ID' => 77, 'description' => 'Square Meter']], 200),
    ]);

    $service = app(FbrReferenceDataService::class);

    $dataset = $service->referenceDataset('sandbox-token');

    expect($dataset['hs_uoms']['5904.9000'])->toBe(['Square Meter']);

    $annexureIds = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/pdi/v2/HS_UOM'))
        ->map(fn ($pair) => $pair[0]['annexure_id'])
        ->values()
        ->all();

    expect($annexureIds)->toBe([1, 2, 3]);
});

test('skips hs codes with no uom mapping', function () {
    Http::fake([
        'gw.fbr.gov.pk/pdi/v1/itemdesccode' => Http::response([
            ['hS_CODE' => '0101.2100', 'description' => 'HORSES'],
        ]),
        'gw.fbr.gov.pk/pdi/v1/uom' => Http::response([]),
        'gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response([], 200),
    ]);

    $service = app(FbrReferenceDataService::class);

    $dataset = $service->referenceDataset('sandbox-token');

    expect($dataset['hs_uoms'])->toBe([]);
});

test('fbr:import-reference-data writes json dataset to output path', function () {
    Http::fake([
        'gw.fbr.gov.pk/pdi/v1/itemdesccode' => Http::response([
            ['hS_CODE' => '0101.2100', 'description' => 'HORSES'],
        ]),
        'gw.fbr.gov.pk/pdi/v1/uom' => Http::response([
            ['uoM_ID' => 13, 'description' => 'KG'],
        ]),
        'gw.fbr.gov.pk/pdi/v2/HS_UOM*' => Http::response([
            ['uoM_ID' => 13, 'description' => 'KG'],
        ]),
    ]);

    $output = storage_path('app/fbr/test-reference.json');

    $this->artisan('fbr:import-reference-data', ['--token' => 'sandbox-token', '--output' => $output])
        ->assertSuccessful();

    $dataset = json_decode(File::get($output), true);

    expect($dataset['hs_codes'])->toBe(['0101.2100' => 'HORSES'])
        ->and($dataset['hs_uoms']['0101.2100'])->toBe(['KG'])
        ->and($dataset['uoms'])->toBe([['uoM_ID' => 13, 'description' => 'KG']]);

    File::delete($output);
});

test('fbr:import-reference-data fails without a token', function () {
    config(['fbr.sandbox_token' => null]);

    $this->artisan('fbr:import-reference-data')
        ->assertExitCode(1);
});
