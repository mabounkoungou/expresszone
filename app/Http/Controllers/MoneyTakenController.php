<?php

namespace App\Http\Controllers;

use App\Models\MoneyTaken;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MoneyTakenController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MoneyTaken::class);
        $user = $request->user('api');
        $warehouseIds = $user->is_all_warehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')->all()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->all();
        $warehouseId = $request->input('warehouse_id');
        if ($warehouseId !== null && $warehouseId !== '' && ! in_array((int) $warehouseId, $warehouseIds, true)) {
            abort(403, 'You are not assigned to this branch.');
        }
        $selectedWarehouseIds = $warehouseId !== null && $warehouseId !== '' ? [(int) $warehouseId] : $warehouseIds;
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $personName = trim((string) $request->input('person', ''));

        $moneyTakenQuery = MoneyTaken::query()->where(function ($query) use ($selectedWarehouseIds, $user) {
            $query->whereIn('warehouse_id', $selectedWarehouseIds);
            if ($user->is_all_warehouses) {
                $query->orWhereNull('warehouse_id');
            }
        });

        if ($dateFrom) {
            $moneyTakenQuery->whereDate('date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $moneyTakenQuery->whereDate('date', '<=', $dateTo);
        }

        if ($personName !== '') {
            $moneyTakenQuery->where(function ($query) use ($personName) {
                $query->where('person_name', 'like', '%' . $personName . '%')
                    ->orWhereHas('person', function ($subQuery) use ($personName) {
                        $subQuery->where('firstname', 'like', '%' . $personName . '%')
                            ->orWhere('lastname', 'like', '%' . $personName . '%')
                            ->orWhere('username', 'like', '%' . $personName . '%');
                    });
            });
        }

        $totalSalesQuery = Sale::whereNull('deleted_at')->whereIn('warehouse_id', $selectedWarehouseIds);
        if ($dateFrom) {
            $totalSalesQuery->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $totalSalesQuery->whereDate('date', '<=', $dateTo);
        }

        $totalSales = (float) $totalSalesQuery->sum('GrandTotal');
        $totalCollected = (float) $totalSalesQuery->sum('paid_amount');
        $totalTaken = (float) $moneyTakenQuery->sum('amount');

        return response()->json([
            'total_sales' => $totalSales,
            'total_collected' => $totalCollected,
            'total_taken' => $totalTaken,
            'remaining' => max(0, $totalCollected - $totalTaken),
            'users' => User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->orderBy('lastname')->get(['id', 'firstname', 'lastname', 'username']),
            'warehouses' => Warehouse::whereNull('deleted_at')->whereIn('id', $warehouseIds)->get(['id', 'name']),
            'transactions' => $moneyTakenQuery->with(['person:id,firstname,lastname,username', 'recorder:id,firstname,lastname,username'])
                ->latest('date')->latest('time')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MoneyTaken::class);
        $validated = $request->validate([
            'person_name' => 'required|string|max:255',
            'person_id' => 'nullable|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'reason' => 'nullable|string|max:1000',
            'warehouse_id' => 'required|integer',
        ]);

        $user = $request->user('api');
        $allowedWarehouseIds = $user->is_all_warehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')->all()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->all();
        if (! in_array((int) $validated['warehouse_id'], $allowedWarehouseIds, true)) {
            abort(403, 'You are not assigned to this branch.');
        }

        $transaction = DB::transaction(function () use ($validated, $request) {
            $totalSales = (float) Sale::whereNull('deleted_at')->where('warehouse_id', $validated['warehouse_id'])->sum('GrandTotal');
            $totalTaken = (float) MoneyTaken::where('warehouse_id', $validated['warehouse_id'])->lockForUpdate()->sum('amount');
            $remaining = round($totalSales - $totalTaken, 2);
            $amount = round((float) $validated['amount'], 2);
            if ($amount > $remaining) {
                abort(422, 'The amount taken cannot exceed the remaining balance of GH₵' . number_format(max(0, $remaining), 2) . '.');
            }
            return MoneyTaken::create(array_merge($validated, ['recorded_by' => $request->user('api')->id]));
        });

        return response()->json($transaction->load(['person', 'recorder']), 201);
    }
}
