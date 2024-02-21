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
            $table->unsignedBigInteger('answer_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_quiz_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('answer_id')->nullable(false)->change();
        });
    }
};
