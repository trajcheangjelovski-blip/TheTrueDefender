<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            // First-party anonymous id kept in the reader's localStorage. No PII.
            $table->string('visitor_id', 40)->unique();
            $table->string('path', 255)->nullable();
            $table->string('device', 16)->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
