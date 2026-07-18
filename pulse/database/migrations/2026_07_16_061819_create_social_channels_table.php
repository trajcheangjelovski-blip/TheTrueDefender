<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_channels', function (Blueprint $table) {
            $table->id();
            $table->string('driver');   // telegram | x | facebook | instagram | truth
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // credentials/tokens per driver
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_channels');
    }
};
