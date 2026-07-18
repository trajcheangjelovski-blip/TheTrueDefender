<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('allow_comments')->default(false);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            // Shown publicly:
            $table->string('name');
            $table->string('surname');
            $table->text('body');

            // Kept private (admin-only):
            $table->string('email');
            $table->string('phone');

            $table->string('status')->default('pending'); // pending | approved | rejected | spam
            $table->string('ip_hash', 64)->nullable();     // abuse tracking, not shown
            $table->timestamps();

            $table->index(['post_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('allow_comments');
        });
    }
};
