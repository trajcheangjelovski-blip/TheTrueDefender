<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // ISO 3166-1 alpha-2, from Cloudflare's CF-IPCountry header. No IP stored.
            $table->string('country', 2)->nullable()->after('device');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
