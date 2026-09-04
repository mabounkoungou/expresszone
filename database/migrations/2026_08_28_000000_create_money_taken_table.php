<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMoneyTakenTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('money_taken')) {
            return;
        }

        Schema::create('money_taken', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('person_id');
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->time('time');
            $table->text('reason')->nullable();
            $table->unsignedInteger('recorded_by');
            $table->timestamps();
            $table->foreign('person_id')->references('id')->on('users');
            $table->foreign('recorded_by')->references('id')->on('users');
            $table->index('date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('money_taken');
    }
}
