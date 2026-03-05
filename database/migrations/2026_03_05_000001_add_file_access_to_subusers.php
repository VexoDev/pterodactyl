<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddFileAccessToSubusers extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subusers', function (Blueprint $table) {
            $table->json('file_access')->nullable()->after('permissions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subusers', function (Blueprint $table) {
            $table->dropColumn('file_access');
        });
    }
}
