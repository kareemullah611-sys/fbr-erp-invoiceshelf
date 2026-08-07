<?php

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Artisan;

use function Pest\Faker\fake;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('company setting belongs to company', function () {
    $setting = CompanySetting::factory()->create();

    $this->assertTrue($setting->company()->exists());
});

test('set settings', function () {
    $key = fake()->name;

    $value = fake()->word;

    $company = Company::factory()->create();

    CompanySetting::setSettings([$key => $value], $company->id);

    $response = CompanySetting::getSetting($key, $company->id);

    $this->assertEquals($value, $response);
});

test('set settings stores null values as empty strings', function () {
    $company = Company::factory()->create();

    CompanySetting::setSettings(['fbr_sandbox_token' => null], $company->id);

    $this->assertEquals('', CompanySetting::getSetting('fbr_sandbox_token', $company->id));
});

test('get settings', function () {
    $key = fake()->name;

    $value = fake()->word;

    $company = Company::factory()->create();

    CompanySetting::setSettings([$key => $value], $company->id);

    $response = CompanySetting::getSettings([$key], $company->id);

    $this->assertEquals([$key => $value], $response->toArray());
});
