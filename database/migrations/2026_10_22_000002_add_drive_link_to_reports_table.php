<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('drive_link', 1000)->nullable()->after('file_path');
        });

        DB::statement('ALTER TABLE reports MODIFY file_path VARCHAR(255) NULL');
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('drive_link');
        });

        DB::statement('ALTER TABLE reports MODIFY file_path VARCHAR(255) NOT NULL');
    }
};
