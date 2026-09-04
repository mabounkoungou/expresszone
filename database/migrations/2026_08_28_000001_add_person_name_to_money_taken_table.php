<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPersonNameToMoneyTakenTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('money_taken', 'person_name')) {
            Schema::table('money_taken', function ($table) {
                $table->string('person_name')->nullable()->after('person_id');
            });
        }

        DB::statement('ALTER TABLE money_taken MODIFY person_id INT UNSIGNED NULL');
    }

    public function down()
    {
        if (Schema::hasColumn('money_taken', 'person_name')) {
            Schema::table('money_taken', function ($table) {
                $table->dropColumn('person_name');
            });
        }

        DB::statement('ALTER TABLE money_taken MODIFY person_id INT UNSIGNED NOT NULL');
    }
}
