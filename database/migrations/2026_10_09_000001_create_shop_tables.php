<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('customer')->index();
            $t->string('google_id')->nullable()->unique();
            $t->boolean('active')->default(true);
        });
        Schema::create('product_models', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('brand')->index();
            $t->string('name');
            $t->json('description');
            $t->string('category')->index();
            $t->json('images');
            $t->json('supported_ram');
            $t->json('supported_ssd');
            $t->json('operating_systems');
            $t->json('specifications');
            $t->boolean('published')->default(false)->index();
            $t->boolean('featured')->default(false);
            $t->unsignedInteger('discount_percent')->default(0);
            $t->json('seo')->nullable();
            $t->unsignedInteger('sold_count')->default(0);
            $t->timestamps();
        });
        Schema::create('inventory_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_model_id')->constrained()->restrictOnDelete();
            $t->string('inventory_id')->unique();
            $t->text('serial_number')->nullable();
            $t->string('serial_hash', 64)->nullable()->unique();
            $t->string('processor');
            $t->string('processor_family')->index();
            $t->unsignedInteger('processor_generation');
            $t->unsignedInteger('ram_gb')->index();
            $t->unsignedInteger('ssd_gb')->index();
            $t->decimal('screen_inches', 3, 1);
            $t->string('resolution');
            $t->string('graphics')->default('Integrated');
            $t->boolean('dedicated_graphics')->default(false);
            $t->boolean('touchscreen')->default(false);
            $t->string('keyboard_layout')->default('QWERTY');
            $t->string('operating_system')->default('Windows 11 Pro');
            $t->unsignedInteger('battery_health')->nullable();
            $t->string('grade', 1)->index();
            $t->text('defects')->nullable();
            $t->json('images')->nullable();
            $t->json('accessories')->nullable();
            $t->unsignedBigInteger('purchase_cents');
            $t->unsignedBigInteger('refurbishment_cents')->default(0);
            $t->unsignedBigInteger('selling_cents');
            $t->string('vat_treatment')->default('standard');
            $t->string('status')->default('awaiting_inspection')->index();
            $t->date('purchase_date')->nullable();
            $t->text('internal_notes')->nullable();
            $t->timestamps();
            $t->index(['product_model_id', 'status', 'grade']);
        });
        Schema::create('components', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->string('type')->index();
            $t->unsignedInteger('capacity')->default(0);
            $t->json('compatible_models');
            $t->unsignedInteger('quantity')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->unsignedBigInteger('cost_cents');
            $t->unsignedBigInteger('upgrade_cents');
            $t->json('specifications')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_unit_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('unit_test_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_unit_id')->constrained()->cascadeOnDelete();
            $t->string('test');
            $t->string('result')->default('not_tested');
            $t->text('notes')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['inventory_unit_id', 'test']);
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->string('token', 64)->unique();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('coupon_code')->nullable();
            $t->string('email')->nullable();
            $t->boolean('reminder_consent')->default(false);
            $t->timestamps();
        });
        Schema::create('cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_model_id')->constrained()->restrictOnDelete();
            $t->json('configuration');
            $t->string('configuration_hash', 64);
            $t->unsignedInteger('quantity')->default(1);
            $t->timestamps();
            $t->unique(['cart_id', 'product_model_id', 'configuration_hash']);
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('type');
            $t->unsignedInteger('value');
            $t->unsignedBigInteger('minimum_cents')->default(0);
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('product_model_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('category')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('number')->unique();
            $t->string('idempotency_key', 64)->unique();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email');
            $t->string('name');
            $t->json('shipping_address');
            $t->string('locale', 2)->default('nl');
            $t->string('status')->default('awaiting_payment')->index();
            $t->boolean('is_test')->default(true);
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('shipping_cents')->default(0);
            $t->unsignedBigInteger('vat_cents')->default(0);
            $t->unsignedBigInteger('total_cents');
            $t->string('coupon_code')->nullable();
            $t->timestamp('reservation_expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('carrier')->nullable();
            $t->string('tracking_number')->nullable();
            $t->string('tracking_url')->nullable();
            $t->text('internal_notes')->nullable();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_unit_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_model_id')->constrained()->restrictOnDelete();
            $t->json('snapshot');
            $t->json('components');
            $t->unsignedBigInteger('price_cents');
            $t->unsignedBigInteger('purchase_cents');
            $t->unsignedBigInteger('refurbishment_cents');
            $t->unsignedBigInteger('upgrade_cost_cents')->default(0);
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('vat_cents')->default(0);
            $t->timestamps();
        });
        Schema::create('inventory_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_unit_id')->constrained()->restrictOnDelete();
            $t->json('components');
            $t->string('status')->default('active')->index();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
        Schema::create('order_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('reference')->nullable()->unique();
            $t->string('status');
            $t->boolean('provider_confirmed')->default(false);
            $t->unsignedBigInteger('amount_cents');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('number')->unique();
            $t->json('snapshot');
            $t->boolean('is_proforma')->default(true);
            $t->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_model_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'product_model_id']);
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_model_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('rating');
            $t->text('body');
            $t->boolean('approved')->default(false);
            $t->boolean('verified_purchase')->default(false);
            $t->text('reply')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'product_model_id']);
        });
        Schema::create('return_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->string('status')->default('requested');
            $t->text('inspection_notes')->nullable();
            $t->string('refund_status')->default('not_started');
            $t->unsignedBigInteger('refund_cents')->default(0);
            $t->timestamps();
        });
        Schema::create('customer_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('label');
            $t->json('address');
            $t->timestamps();
        });
        Schema::create('inquiries', function (Blueprint $t) {
            $t->id();
            $t->string('type')->index();
            $t->string('name');
            $t->string('email');
            $t->json('details');
            $t->string('status')->default('new');
            $t->text('internal_notes')->nullable();
            $t->unsignedBigInteger('quoted_cents')->nullable();
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('type')->default('newsletter');
            $t->foreignId('product_model_id')->nullable()->constrained()->cascadeOnDelete();
            $t->json('configuration')->nullable();
            $t->string('locale', 2);
            $t->string('token', 64)->unique();
            $t->timestamp('consent_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('email_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('subject');
            $t->text('body');
            $t->string('status')->default('draft');
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('email_records', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key')->unique();
            $t->string('recipient');
            $t->string('subject');
            $t->text('html');
            $t->string('status')->default('preview');
            $t->timestamp('sent_at')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('csv_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('filename');
            $t->string('status');
            $t->json('report');
            $t->timestamps();
        });
        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('entity')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('details')->nullable();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'admin_audit_logs', 'csv_imports', 'email_records', 'email_campaigns', 'subscriptions', 'inquiries', 'customer_addresses', 'return_requests', 'reviews', 'wishlists', 'invoices', 'payments', 'order_history', 'inventory_reservations', 'order_items', 'orders', 'coupons', 'cart_items', 'carts', 'unit_test_results', 'inventory_history', 'components', 'inventory_units', 'product_models'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropUnique(['google_id']);
            $t->dropColumn(['role', 'google_id', 'active']);
        });
    }
};
