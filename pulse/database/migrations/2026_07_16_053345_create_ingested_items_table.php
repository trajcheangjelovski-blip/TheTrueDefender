<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingested_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingest_source_id')->constrained()->cascadeOnDelete();
            $table->string('guid');                 // unique id from the feed (guid or link)
            $table->string('source_url', 1000)->nullable();
            $table->string('title');
            $table->string('status')->default('pending'); // pending | processed | skipped | failed
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['ingest_source_id', 'guid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_items');
    }
};
