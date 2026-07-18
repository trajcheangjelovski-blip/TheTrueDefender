<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('code', 16)->unique();              // referral code in ?ref=
            $table->string('status')->default('pending');      // pending | active | suspended
            $table->string('website')->nullable();             // where they'll promote
            $table->text('notes')->nullable();                 // application message / admin notes
            $table->string('payout_method')->nullable();       // e.g. PayPal email, IBAN

            // Per-affiliate overrides — blank means "use the global setting".
            $table->decimal('rate_per_1000', 8, 2)->nullable();
            $table->decimal('share_pct', 5, 2)->nullable();
            $table->decimal('sale_commission_pct', 5, 2)->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('ip_hash', 64);
            $table->string('ua_hash', 64);
            $table->string('path', 500)->nullable();
            $table->boolean('is_valid')->default(true);
            $table->string('invalid_reason')->nullable();      // bot | duplicate | ip-rate-limit
            $table->timestamps();

            $table->index(['affiliate_id', 'ip_hash', 'created_at']);
            $table->index(['affiliate_id', 'is_valid']);
        });

        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('order_total', 10, 2);
            $table->decimal('commission_pct', 5, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->string('status')->default('pending');      // pending | approved | rejected | paid
            $table->timestamps();
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        // Admin permission for the new resource (existing DBs won't re-seed).
        try {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_affiliates']);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            // Table not present yet (fresh install) — DatabaseSeeder covers it.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
    }
};
