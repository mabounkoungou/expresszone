<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddWarehouseIdForeignKeyToClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // First, ensure warehouse_id is a regular signed integer to match warehouses.id type
        DB::statement('ALTER TABLE clients MODIFY warehouse_id INT NULL');

        Schema::table('clients', function (Blueprint $table) {
            // Add foreign key constraint
            try {
                $table->foreign('warehouse_id')
                    ->references('id')
                    ->on('warehouses')
                    ->onUpdate('CASCADE')
                    ->onDelete('SET NULL');
            } catch (\Exception $e) {
                // Foreign key may already exist, that's okay
            }

            // Add index
            try {
                $table->index('warehouse_id');
            } catch (\Exception $e) {
                // Index may already exist, that's okay
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            // This is optional - kept minimal to avoid issues
        });
    }
}
