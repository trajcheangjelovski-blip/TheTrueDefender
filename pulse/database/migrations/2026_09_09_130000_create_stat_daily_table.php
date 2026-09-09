<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stat_daily', function (Blueprint $table) {
            $table->id();
            // One row per calendar day: the site-wide engagement totals for that day.
            $table->date('stat_date')->unique();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stat_daily');
    }
};
