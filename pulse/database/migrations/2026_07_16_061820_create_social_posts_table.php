<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_channel_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | sent | failed
            $table->string('external_id')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'social_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
