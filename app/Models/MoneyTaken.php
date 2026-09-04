<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoneyTaken extends Model
{
    protected $table = 'money_taken';
    protected $fillable = ['person_id', 'person_name', 'amount', 'date', 'time', 'reason', 'recorded_by', 'warehouse_id'];
    protected $casts = ['person_id' => 'integer', 'recorded_by' => 'integer', 'warehouse_id' => 'integer', 'amount' => 'decimal:2', 'date' => 'date:Y-m-d'];

    public function person()
    {
        return $this->belongsTo(User::class, 'person_id');
    }
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
