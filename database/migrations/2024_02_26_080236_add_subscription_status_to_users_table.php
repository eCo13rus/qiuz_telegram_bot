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
        Schema::table('users', function (Blueprint $table) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_subscribed')->default(false); // Добавляет столбец для отслеживания статуса подписки
                $table->string('name')->nullable()->change(); // Делает поле имени необязательным
                $table->string('email')->nullable()->change(); // Делает поле email необязательным
                $table->string('password')->nullable()->change(); // Делает поле пароля необязательным
            });
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_subscribed'); // Удаляет столбец is_subscribed
            // Возвращает поля к состоянию "не nullable"
            $table->string('name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
