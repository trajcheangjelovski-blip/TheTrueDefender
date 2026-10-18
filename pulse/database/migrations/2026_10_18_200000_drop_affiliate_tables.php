<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The affiliate program was removed. Drop its tables only when they hold no rows
 * (production had none); if any data exists, keep it for the records.
 */
return new class extends Migration
{
    private const TABLES = ['affiliate_payouts', 'affiliate_conversions', 'affiliate_clicks', 'affiliates'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t) && DB::table($t)->exists()) {
                return;   // keep everything if any table has data
            }
        }
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('settings')->whereIn('key', ['affiliate_rate_per_1000', 'affiliate_share_pct', 'affiliate_sale_commission_pct', 'affiliate_cookie_days'])->delete();
    }

    public function down(): void
    {
        // Intentionally empty: the program is gone; restore from git history if ever needed.
    }
};
