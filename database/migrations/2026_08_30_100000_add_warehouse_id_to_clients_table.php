<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWarehouseIdToClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Only add warehouse_id if it doesn't already exist
            if (! Schema::hasColumn('clients', 'warehouse_id')) {
                // Add warehouse_id column as nullable to support backward compatibility
                $table->integer('warehouse_id')->nullable()->after('id');

                // Add foreign key constraint
                $table->foreign('warehouse_id')
                    ->references('id')
                    ->on('warehouses')
                    ->onUpdate('CASCADE')
                    ->onDelete('SET NULL');

                // Add index for faster queries
                $table->index('warehouse_id');
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
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
}
