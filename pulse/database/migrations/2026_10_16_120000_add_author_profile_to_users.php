<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give users a real, public author profile so articles can carry a named
     * byline with a bio, credentials and a dedicated author page. This is the
     * E-E-A-T foundation: Google wants accountable, identifiable human authors
     * for news/YMYL content, not an anonymous "Editorial Team" string.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Public author identity.
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('title')->nullable()->after('slug');          // e.g. "Editor-in-Chief"
            $table->text('bio')->nullable()->after('title');             // 2-4 sentence bio
            $table->text('credentials')->nullable()->after('bio');       // experience / expertise
            $table->string('photo_path')->nullable()->after('credentials'); // storage/app/public/...
            $table->string('x_url')->nullable()->after('photo_path');
            $table->string('linkedin_url')->nullable()->after('x_url');
            $table->string('public_email')->nullable()->after('linkedin_url');

            // When true, this user is shown as a public author (byline links to
            // /author/{slug}, listed on the newsroom masthead). Admin-only users
            // stay false and never appear publicly.
            $table->boolean('is_author')->default(false)->after('public_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'slug', 'title', 'bio', 'credentials', 'photo_path',
                'x_url', 'linkedin_url', 'public_email', 'is_author',
            ]);
        });
    }
};
