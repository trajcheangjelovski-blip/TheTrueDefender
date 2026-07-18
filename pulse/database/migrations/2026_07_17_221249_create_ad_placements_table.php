<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();     // e.g. article_mid
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('format')->default('display'); // display | in-article
            $table->string('ad_slot')->nullable();        // AdSense slot ID for this place
            $table->longText('custom_html')->nullable();  // optional custom ad code (overrides AdSense)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placements');
    }
};
