<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MoneyTakenPolicy
{
    use HandlesAuthorization;

    protected function allowed(User $user, string $permissionName): bool
    {
        $permission = Permission::where('name', $permissionName)->first();
        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user): bool
    {
        return $this->allowed($user, 'money_taken_view');
    }
    public function create(User $user): bool
    {
        return $this->allowed($user, 'Sales_add') || $this->allowed($user, 'money_taken_view');
    }
}
