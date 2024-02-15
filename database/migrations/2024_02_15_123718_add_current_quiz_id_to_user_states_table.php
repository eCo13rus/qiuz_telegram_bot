<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_states', function (Blueprint $table) {
            $table->unsignedBigInteger('current_quiz_id')->nullable()->after('state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_states', function (Blueprint $table) {
            $table->dropColumn('current_quiz_id');
        });
    }
};
