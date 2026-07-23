<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('fbr_ntn')->nullable();
            $table->string('fbr_cnic')->nullable();
            $table->string('fbr_registration_type')->nullable();
            $table->string('fbr_sale_channel')->nullable();
            $table->string('fbr_payment_mode')->nullable();
            $table->string('fbr_payment_terms')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'fbr_ntn',
                'fbr_cnic',
                'fbr_registration_type',
                'fbr_sale_channel',
                'fbr_payment_mode',
                'fbr_payment_terms',
            ]);
        });
    }
};
