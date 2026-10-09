<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedInteger('packaging_cost_cents')->default(0);
            $t->unsignedInteger('shipping_cost_cents')->default(0);
            $t->unsignedInteger('payment_fee_cents')->default(0);
        });
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->timestamp('notified_at')->nullable();
        });
        Schema::table('email_campaigns', function (Blueprint $t) {
            $t->string('segment')->default('all');
            $t->unsignedInteger('recipient_count')->default(0);
        });
        Schema::table('carts', function (Blueprint $t) {
            $t->unique('user_id');
            $t->string('reminder_token', 64)->nullable()->unique();
        });
        Schema::create('review_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('review_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('reason');
            $t->timestamps();
            $t->unique(['review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['packaging_cost_cents', 'shipping_cost_cents', 'payment_fee_cents']));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('notified_at'));
        Schema::table('email_campaigns', fn (Blueprint $t) => $t->dropColumn(['segment', 'recipient_count']));
        Schema::table('carts', function (Blueprint $t) {
            $t->index('user_id', 'carts_user_restore_index');
            $t->dropUnique(['user_id']);
            $t->dropUnique(['reminder_token']);
            $t->dropColumn('reminder_token');
        });
    }
};
