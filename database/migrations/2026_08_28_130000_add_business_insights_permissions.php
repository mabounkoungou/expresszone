<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddBusinessInsightsPermissions extends Migration
{
    public function up()
    {
        $permissions = [
            ['name' => 'business_insights_view', 'label' => 'Business Insights View', 'description' => 'View business insights reports.'],
            ['name' => 'business_insights_run', 'label' => 'Business Insights Run', 'description' => 'Run business insights analysis.'],
            ['name' => 'business_insights_export', 'label' => 'Business Insights Export', 'description' => 'Export business insights reports.'],
        ];

        foreach ($permissions as $permission) {
            $permissionId = DB::table('permissions')->where('name', $permission['name'])->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId(array_merge($permission, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            if (DB::table('roles')->where('id', 1)->exists() && ! DB::table('permission_role')
                ->where('permission_id', $permissionId)->where('role_id', 1)->exists()) {
                DB::table('permission_role')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => 1,
                ]);
            }
        }
    }

    public function down()
    {
        $ids = DB::table('permissions')->whereIn('name', [
            'business_insights_view',
            'business_insights_run',
            'business_insights_export',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
}
