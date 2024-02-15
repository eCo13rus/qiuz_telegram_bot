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
        Schema::table('user_quiz_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz_id')->after('id')->nullable();
        });
        ;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_quiz_responses', function (Blueprint $table) {
            $table->dropColumn('quiz_id');
        });
    }
};
