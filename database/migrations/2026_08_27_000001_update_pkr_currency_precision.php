<?php

use App\Models\Currency;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Currency::where('code', 'PKR')->update([
            'precision' => 2,
        ]);
    }

    public function down(): void
    {
        Currency::where('code', 'PKR')->update([
            'precision' => 0,
        ]);
    }
};
