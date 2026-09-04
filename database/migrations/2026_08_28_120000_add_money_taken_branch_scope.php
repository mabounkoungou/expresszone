<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddMoneyTakenBranchScope extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('money_taken', 'warehouse_id')) {
            Schema::table('money_taken', function (Blueprint $table) {
                $table->unsignedInteger('warehouse_id')->nullable()->after('recorded_by');
                $table->index('warehouse_id');
            });
        }

        $permissionId = DB::table('permissions')->where('name', 'money_taken_view')->value('id');
        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'money_taken_view',
                'label' => 'Money Taken View',
                'description' => 'View money taken totals and transactions for assigned branches.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('roles')->where('id', 1)->exists() && ! DB::table('permission_role')
            ->where('permission_id', $permissionId)->where('role_id', 1)->exists()) {
            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id' => 1,
            ]);
        }
    }

    public function down()
    {
        $permissionId = DB::table('permissions')->where('name', 'money_taken_view')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        if (Schema::hasColumn('money_taken', 'warehouse_id')) {
            Schema::table('money_taken', function (Blueprint $table) {
                $table->dropIndex(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
}
