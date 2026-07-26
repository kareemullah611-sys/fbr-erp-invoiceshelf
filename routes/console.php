<?php

use App\Models\CompanySetting;
use App\Models\RecurringInvoice;
use App\Services\Document\RecurringInvoiceService;
use App\Support\Setup\InstallUtils;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

// Only run in demo environment
if (config('app.env') === 'demo') {
    Schedule::command('reset:app --force')
        ->daily()
        ->runInBackground()
        ->withoutOverlapping();
}

if (InstallUtils::isDbCreated()) {
    Schedule::command('check:invoices:status')
        ->daily();

    Schedule::command('check:estimates:status')
        ->daily();

    if (Schema::hasTable('recurring_invoices')) {
        $recurringInvoices = RecurringInvoice::where('status', 'ACTIVE')->get();
        foreach ($recurringInvoices as $recurringInvoice) {
            $timeZone = CompanySetting::getSetting('time_zone', $recurringInvoice->company_id);

            Schedule::call(function () use ($recurringInvoice) {
                app(RecurringInvoiceService::class)->generateInvoice($recurringInvoice);
            })->cron($recurringInvoice->frequency)->timezone($timeZone);
        }
    }
}
