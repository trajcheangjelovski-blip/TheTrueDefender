<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent (301) redirects for retired article slugs — e.g. when two articles
 * covering the same event are consolidated, the retired /post/{old-slug} sends
 * readers and crawlers to the surviving article instead of 404-ing (which would
 * lose the URL's accumulated links/authority and leave a dead result in search).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_slug')->unique();
            $table->string('to_slug');
            $table->string('reason')->nullable(); // e.g. "merged duplicate", editor note
            $table->timestamps();

            $table->index('to_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_redirects');
    }
};
