<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->longText('credentials');
            $table->timestamps();
        });
        Schema::create('marketplace_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_unit_id')->unique()->constrained()->restrictOnDelete();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_draft_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->json('data');
            $table->timestamps();
            $table->unique(['marketplace_draft_id', 'channel']);
        });
        Schema::create('marketplace_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_draft_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('status')->default('queued')->index();
            $table->json('channels');
            $table->json('snapshot');
            $table->json('results')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
        Schema::create('marketplace_photos', function (Blueprint $table) {
            $table->id();
            $table->string('file', 60)->unique();
            $table->string('disk', 20)->default('public');
            $table->string('path', 190);
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        foreach (['marketplace_photos', 'marketplace_actions', 'marketplace_listings', 'marketplace_drafts', 'marketplace_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
