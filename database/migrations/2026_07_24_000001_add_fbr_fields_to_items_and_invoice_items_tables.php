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
        Schema::table('items', function (Blueprint $table) {
            $this->addFbrColumns($table);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $this->addFbrColumns($table);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $this->dropFbrColumns($table);
        });

        Schema::table('items', function (Blueprint $table) {
            $this->dropFbrColumns($table);
        });
    }

    private function addFbrColumns(Blueprint $table): void
    {
        $table->string('fbr_hs_code')->nullable();
        $table->string('fbr_uom')->nullable();
        $table->string('fbr_sale_type')->nullable();
        $table->string('fbr_sro_no')->nullable();
        $table->string('fbr_sro_item_no')->nullable();
        $table->unsignedBigInteger('fbr_fixed_notified_value')->nullable();
        $table->unsignedBigInteger('fbr_sales_tax_withheld')->nullable();
        $table->unsignedBigInteger('fbr_further_tax')->nullable();
        $table->unsignedBigInteger('fbr_extra_tax')->nullable();
        $table->unsignedBigInteger('fbr_fed_payable')->nullable();
    }

    private function dropFbrColumns(Blueprint $table): void
    {
        $table->dropColumn([
            'fbr_hs_code',
            'fbr_uom',
            'fbr_sale_type',
            'fbr_sro_no',
            'fbr_sro_item_no',
            'fbr_fixed_notified_value',
            'fbr_sales_tax_withheld',
            'fbr_further_tax',
            'fbr_extra_tax',
            'fbr_fed_payable',
        ]);
    }
};
