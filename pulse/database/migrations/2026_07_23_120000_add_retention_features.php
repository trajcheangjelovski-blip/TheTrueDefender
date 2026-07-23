<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('votes')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Avoid emailing the same reply notification twice.
        Schema::table('comments', function (Blueprint $table) {
            $table->timestamp('reply_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comments', fn (Blueprint $t) => $t->dropColumn('reply_notified_at'));
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
