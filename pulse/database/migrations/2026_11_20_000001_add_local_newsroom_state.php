<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_items', function (Blueprint $table) {
            $table->json('evidence')->nullable();
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->json('evidence_snapshot')->nullable();
            $table->string('truth_profile')->nullable();
            $table->timestamp('content_updated_at')->nullable();
        });
        Schema::create('newsroom_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('profile');
            $table->string('idempotency_key', 64)->unique();
            $table->json('payload');
            $table->text('external_url')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'profile']);
        });
        Schema::create('newsroom_update_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('status')->default('pending');
            $table->json('evidence');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsroom_update_checks');
        Schema::dropIfExists('newsroom_outbox');
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['evidence_snapshot', 'truth_profile', 'content_updated_at']));
        Schema::table('ingested_items', fn (Blueprint $table) => $table->dropColumn('evidence'));
    }
};
