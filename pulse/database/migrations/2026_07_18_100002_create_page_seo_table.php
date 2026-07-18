<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_seo', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // home, about, contact, privacy, terms, shop
            $table->string('label');                  // human name for the admin
            $table->string('path')->default('/');     // relative URL for canonical/GSC match

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('focus_keyword')->nullable();

            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->json('seo_analysis')->nullable();
            $table->timestamp('seo_analyzed_at')->nullable();

            $table->decimal('gsc_position', 6, 2)->nullable();
            $table->unsignedInteger('gsc_clicks')->nullable();
            $table->unsignedInteger('gsc_impressions')->nullable();
            $table->decimal('gsc_ctr', 5, 2)->nullable();
            $table->timestamp('gsc_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_seo');
    }
};
