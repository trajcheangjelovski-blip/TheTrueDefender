<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // All outlets whose reporting was synthesized into this article:
            // [{name, url}, ...]. The first entry is the original source_name/url;
            // the multi-source merge appends the others.
            $table->json('sources')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('sources');
        });
    }
};
