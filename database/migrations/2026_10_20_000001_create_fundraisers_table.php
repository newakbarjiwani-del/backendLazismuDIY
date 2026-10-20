<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundraisers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->timestamps();
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->foreignId('fundraiser_id')->nullable()->after('campaign_id')->constrained('fundraisers')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('fundraiser_id')->nullable()->after('campaign_id')->constrained('fundraisers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fundraiser_id');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fundraiser_id');
        });

        Schema::dropIfExists('fundraisers');
    }
};
