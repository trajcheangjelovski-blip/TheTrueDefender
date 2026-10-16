<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Null preserves legacy category-based Opinion labels without changing any article.
            $table->string('content_type', 16)->nullable();
            $table->text('image_caption')->nullable();
            $table->string('image_credit')->nullable();
            $table->text('image_credit_url')->nullable();
            $table->string('image_kind', 24)->nullable();
            $table->text('image_rights')->nullable();
            $table->boolean('image_rights_confirmed')->default(false);
            $table->json('related_post_ids')->nullable();
            $table->text('editorial_review_notes')->nullable();
            $table->text('draft_title')->nullable();
            $table->text('draft_excerpt')->nullable();
            $table->longText('draft_body')->nullable();
            $table->json('draft_sources')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('identity_status', 24)->default('unverified');
            $table->text('identity_disclosure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn([
            'content_type', 'image_caption', 'image_credit', 'image_credit_url', 'image_kind',
            'image_rights', 'image_rights_confirmed', 'related_post_ids', 'editorial_review_notes',
            'draft_title', 'draft_excerpt', 'draft_body', 'draft_sources',
        ]));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['identity_status', 'identity_disclosure']));
    }
};
