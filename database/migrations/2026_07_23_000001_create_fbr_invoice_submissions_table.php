<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fbr_invoice_submissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->unsignedInteger('company_id');
            $table->string('environment');
            $table->string('status');
            $table->string('fbr_invoice_number')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_invoice_submissions');
    }
};
