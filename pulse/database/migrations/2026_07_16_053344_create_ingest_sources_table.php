<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('feed_url');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_publish')->default(false); // ON = publish immediately; OFF = save as draft
            $table->boolean('fetch_images')->default(false);  // republishing source images is the riskiest part — off by default
            $table->unsignedSmallInteger('max_items')->default(5); // per fetch
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_sources');
    }
};
