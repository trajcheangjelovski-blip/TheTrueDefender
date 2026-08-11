<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which topics a push endpoint follows (account-free: keyed by the
        // existing browser subscription, not a user).
        Schema::create('push_subscription_tag', function (Blueprint $table) {
            $table->foreignId('push_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['push_subscription_id', 'tag_id']);
        });

        // Which topics an email subscriber follows (for future segmented digests).
        Schema::create('subscriber_tag', function (Blueprint $table) {
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['subscriber_id', 'tag_id']);
        });

        // topics_only=false (default) → global subscriber, gets every push exactly
        // as today (no behaviour change). true → the reader opted to be alerted
        // ONLY about the topics they follow. Breaking news always overrides this.
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->boolean('topics_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscription_tag');
        Schema::dropIfExists('subscriber_tag');
        Schema::table('push_subscriptions', fn (Blueprint $t) => $t->dropColumn('topics_only'));
    }
};
