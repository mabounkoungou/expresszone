<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Product;
use App\Models\Client;
use App\Models\PaymentSale;
use App\Traits\CalculatesCogsAndAverageCost;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class ReportQuestionService
{
    use CalculatesCogsAndAverageCost;

    /**
     * Execute daily sales summary report
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @param int|null $warehouseId
     * @return array
     */
    public function dailySalesSummary(string $dateFrom, string $dateTo, ?int $warehouseId = null): array
    {
        $user = Auth::user();
        $viewRecords = $user ? $user->hasRecordView() : false;

        // Get user's accessible warehouses
        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);

        // Build query
        $query = Sale::whereNull('deleted_at')
            ->where('statut', 'completed')
            ->whereBetween('date', [$dateFrom, $dateTo]);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        } else {
            $query->whereIn('warehouse_id', $warehouseIds);
        }

        if (!$viewRecords) {
            $query->where('user_id', $user->id);
        }

        // Get aggregates
        $sales = $query->get();

        $transactions = $sales->count();
        $revenue = $sales->sum('GrandTotal');
        $tax = $sales->sum('TaxNet');
        $discount = $sales->sum('discount');

        // Calculate profit using COGS
        $cogsPack = $this->calcCogsAndAvgCostFast($dateFrom, $dateTo, $warehouseId, $warehouseIds);
        $cogsFIFO = $cogsPack['fifo'] ?? 0.0;

        // Get expenses for the period
        $expenses = DB::table('expenses')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            }, function ($q) use ($warehouseIds) {
                return $q->whereIn('warehouse_id', $warehouseIds);
            })
            ->when(!$viewRecords, function ($q) use ($user) {
                return $q->where('user_id', $user->id);
            })
            ->sum('amount');

        $profit = $revenue - $cogsFIFO - $expenses;

        return [
            'transactions' => $transactions,
            'revenue' => (float) $revenue,
            'tax' => (float) $tax,
            'discount' => (float) $discount,
            'profit' => (float) $profit,
        ];
    }

    /**
     * Execute sales by product report
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @param int|null $warehouseId
     * @param array $filters
     * @return array
     */
    public function salesByProduct(string $dateFrom, string $dateTo, ?int $warehouseId = null, array $filters = []): array
    {
        $user = Auth::user();
        $viewRecords = $user ? $user->hasRecordView() : false;

        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);
        $limit = $filters['limit'] ?? 10;
        $sortBy = $filters['sort_by'] ?? 'profit';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        // Build base query
        $query = SaleDetail::join('sales as s', 's.id', '=', 'sale_details.sale_id')
            ->join('products as p', 'p.id', '=', 'sale_details.product_id')
            ->whereNull('s.deleted_at')
            ->where('s.statut', 'completed')
            ->whereBetween('sale_details.date', [$dateFrom, $dateTo]);

        if ($warehouseId) {
            $query->where('s.warehouse_id', $warehouseId);
        } else {
            $query->whereIn('s.warehouse_id', $warehouseIds);
        }

        if (!$viewRecords) {
            $query->where('s.user_id', $user->id);
        }

        // Aggregate by product (COALESCE name so chart/table never get null)
        $results = $query
            ->select(
                'sale_details.product_id',
                DB::raw('COALESCE(NULLIF(TRIM(p.name), ""), CONCAT("Product #", sale_details.product_id)) as name'),
                DB::raw('SUM(sale_details.quantity) as qty'),
                DB::raw('SUM(sale_details.total) as revenue'),
                DB::raw('SUM(sale_details.quantity * p.cost) as cost')
            )
            ->groupBy('sale_details.product_id', 'p.name')
            ->get()
            ->map(function ($item) {
                $revenue = (float) $item->revenue;
                $cost = (float) $item->cost;
                $profit = $revenue - $cost;
                $marginPercent = $revenue > 0 ? (($profit / $revenue) * 100) : 0;
                $displayName = $item->name !== null && (string) $item->name !== '' ? (string) $item->name : 'Product #' . $item->product_id;

                return [
                    'product_id' => $item->product_id,
                    'name' => $displayName,
                    'qty' => (float) $item->qty,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin_percent' => round($marginPercent, 2),
                ];
            });

        // Sort
        $results = $results->sortBy(function ($item) use ($sortBy) {
            return $item[$sortBy];
        }, SORT_REGULAR, $sortDir === 'desc');

        // Limit
        return $results->take($limit)->values()->toArray();
    }

    /**
     * Execute late payments report
     *
     * @param array $filters
     * @return array
     */
    public function latePayments(array $filters = []): array
    {
        $minDaysOverdue = (int) ($filters['min_days_overdue'] ?? 30);
        $today = Carbon::today()->startOfDay();

        // Get sales with outstanding amounts (only invoice date on or before today)
        $sales = Sale::whereNull('deleted_at')
            ->where('statut', 'completed')
            ->whereRaw('(GrandTotal - paid_amount) > 0.01')
            ->whereDate('date', '<=', $today->toDateString())
            ->with('client')
            ->get();

        $customerData = [];

        foreach ($sales as $sale) {
            $dueAmount = $sale->GrandTotal - $sale->paid_amount;
            $saleDate = Carbon::parse($sale->date)->startOfDay();
            // Use absolute difference: past invoices => positive days overdue (we already filter date <= today in the query)
            $daysOverdue = (int) $today->diffInDays($saleDate, true);

            if ($daysOverdue >= $minDaysOverdue) {
                $customerId = $sale->client_id;
                $customerName = $sale->client ? $sale->client->name : 'Unknown';

                if (!isset($customerData[$customerId])) {
                    $customerData[$customerId] = [
                        'customer_id' => $customerId,
                        'name' => $customerName,
                        'invoices_count' => 0,
                        'outstanding_amount' => 0,
                        'max_days_overdue' => 0,
                    ];
                }

                $customerData[$customerId]['invoices_count']++;
                $customerData[$customerId]['outstanding_amount'] += $dueAmount;
                $customerData[$customerId]['max_days_overdue'] = max(
                    $customerData[$customerId]['max_days_overdue'],
                    $daysOverdue
                );
            }
        }

        // Sort by outstanding_amount descending
        usort($customerData, function ($a, $b) {
            return $b['outstanding_amount'] <=> $a['outstanding_amount'];
        });

        return array_values($customerData);
    }

    /**
     * Generate insights by comparing two periods
     *
     * @param array $currentData
     * @param array $compareData
     * @return string
     */
    public function generateInsights(array $currentData, array $compareData): string
    {
        // Placeholder for AI integration - simple comparison for now
        $currentProfit = $currentData['profit'] ?? 0;
        $compareProfit = $compareData['profit'] ?? 0;
        $profitDelta = $currentProfit - $compareProfit;
        $profitPercentChange = $compareProfit != 0 ? (($profitDelta / abs($compareProfit)) * 100) : 0;

        $currentRevenue = $currentData['revenue'] ?? 0;
        $compareRevenue = $compareData['revenue'] ?? 0;
        $revenueDelta = $currentRevenue - $compareRevenue;

        $currentDiscount = $currentData['discount'] ?? 0;
        $compareDiscount = $compareData['discount'] ?? 0;
        $discountDelta = $currentDiscount - $compareDiscount;

        $insights = [];
        
        if (abs($profitDelta) > 0.01) {
            $direction = $profitDelta > 0 ? 'increased' : 'decreased';
            $insights[] = sprintf(
                "Profit %s from %s to %s (change: %s, %.1f%%)",
                $direction,
                number_format($compareProfit, 2),
                number_format($currentProfit, 2),
                number_format($profitDelta, 2),
                abs($profitPercentChange)
            );
        }

        if (abs($revenueDelta) > 0.01) {
            $insights[] = sprintf(
                "Revenue changed by %s (from %s to %s)",
                number_format($revenueDelta, 2),
                number_format($compareRevenue, 2),
                number_format($currentRevenue, 2)
            );
        }

        if (abs($discountDelta) > 0.01) {
            $insights[] = sprintf(
                "Discount changed by %s (from %s to %s)",
                number_format($discountDelta, 2),
                number_format($compareDiscount, 2),
                number_format($currentDiscount, 2)
            );
        }

        if (empty($insights)) {
            return "No significant changes detected between periods.";
        }

        return implode('. ', $insights) . '.';
    }

    public function businessInsights(string $dateFrom, string $dateTo, ?int $warehouseId = null): array
    {
        $user = Auth::user();
        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);
        $currentFrom = Carbon::parse($dateFrom)->startOfDay();
        $currentTo = Carbon::parse($dateTo)->endOfDay();
        $days = $currentFrom->diffInDays($currentTo) + 1;
        $previousTo = $currentFrom->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $warehouses = DB::table('warehouses')->whereNull('deleted_at')
            ->when($warehouseId, fn ($query) => $query->where('id', $warehouseId), fn ($query) => $query->whereIn('id', $warehouseIds))
            ->get(['id', 'name']);
        $branchMetrics = [];
        foreach ($warehouses as $warehouse) {
            $current = $this->businessPeriodMetrics($currentFrom, $currentTo, (int) $warehouse->id, $user);
            $previous = $this->businessPeriodMetrics($previousFrom, $previousTo, (int) $warehouse->id, $user);
            $current['sales_change_percent'] = $this->percentChange($previous['revenue'], $current['revenue']);
            $current['profit_change_percent'] = $this->percentChange($previous['profit'], $current['profit']);
            $current['orders_change_percent'] = $this->percentChange($previous['orders'], $current['orders']);
            $branchMetrics[] = ['branch_id' => (int) $warehouse->id, 'branch' => $warehouse->name, 'current' => $current, 'previous' => $previous];
        }
        $overall = $this->businessPeriodMetrics($currentFrom, $currentTo, $warehouseId, $user);
        $previousOverall = $this->businessPeriodMetrics($previousFrom, $previousTo, $warehouseId, $user);
        $overall['sales_change_percent'] = $this->percentChange($previousOverall['revenue'], $overall['revenue']);
        $overall['profit_change_percent'] = $this->percentChange($previousOverall['profit'], $overall['profit']);
        $overall['orders_change_percent'] = $this->percentChange($previousOverall['orders'], $overall['orders']);

        $metrics = ['period' => ['from' => $dateFrom, 'to' => $dateTo], 'overall' => $overall, 'branches' => $branchMetrics];
        return ['metrics' => $metrics, 'analysis' => $this->interpretBusinessMetrics($metrics)];
    }

    protected function businessPeriodMetrics(Carbon $from, Carbon $to, $warehouseId, $user): array
    {
        $query = Sale::whereNull('deleted_at')->where('statut', 'completed')->whereBetween('date', [$from, $to])->where('warehouse_id', $warehouseId);
        $sales = $query->selectRaw('COUNT(*) orders, COALESCE(SUM(GrandTotal),0) revenue, COALESCE(SUM(paid_amount),0) collected')->first();
        $expenses = DB::table('expenses')->whereNull('deleted_at')->where('warehouse_id', $warehouseId)->whereBetween('date', [$from, $to])->sum('amount');
        $revenue = (float) $sales->revenue;
        $expenseTotal = (float) $expenses;
        return ['orders' => (int) $sales->orders, 'revenue' => $revenue, 'collected' => (float) $sales->collected, 'expenses' => $expenseTotal, 'profit' => $revenue - $expenseTotal];
    }

    protected function percentChange($previous, $current): float
    {
        return $previous == 0 ? ($current == 0 ? 0.0 : 100.0) : round((($current - $previous) / abs($previous)) * 100, 2);
    }

    protected function interpretBusinessMetrics(array $metrics): array
    {
        $branches = $metrics['branches'];
        usort($branches, fn ($a, $b) => $b['current']['revenue'] <=> $a['current']['revenue']);
        $best = $branches[0]['branch'] ?? null;
        $attention = null;
        $positives = [];
        $warnings = [];
        $recommendations = [];
        foreach (array_reverse($branches) as $branch) {
            if (($branch['current']['sales_change_percent'] ?? 0) < 0) { $attention = $branch['branch']; break; }
        }
        $overall = $metrics['overall'];
        $salesChange = (float) ($overall['sales_change_percent'] ?? 0);
        $profitChange = (float) ($overall['profit_change_percent'] ?? 0);
        $ordersChange = (float) ($overall['orders_change_percent'] ?? 0);
        $score = 50;
        $score += $salesChange > 0 ? 15 : ($salesChange < 0 ? -15 : 0);
        $score += $profitChange > 0 ? 15 : ($profitChange < 0 ? -15 : 0);
        $score += $ordersChange > 0 ? 10 : ($ordersChange < 0 ? -10 : 0);
        $score += $overall['orders'] > 0 ? 10 : -10;
        $score = max(0, min(100, $score));
        if ($salesChange > 0) $positives[] = sprintf('Revenue increased by %.2f%% compared with the previous equivalent period.', $salesChange);
        if ($profitChange > 0) $positives[] = sprintf('Profit increased by %.2f%% compared with the previous equivalent period.', $profitChange);
        if ($ordersChange > 0) $positives[] = sprintf('Completed orders increased by %.2f%% compared with the previous equivalent period.', $ordersChange);
        if ($best) $positives[] = sprintf('%s is the best-performing branch by current revenue.', $best);
        if ($salesChange < 0) $warnings[] = sprintf('Revenue decreased by %.2f%% compared with the previous equivalent period.', abs($salesChange));
        if ($profitChange < 0) $warnings[] = sprintf('Profit decreased by %.2f%% compared with the previous equivalent period.', abs($profitChange));
        if ($attention) $warnings[] = sprintf('%s has declining sales compared with its previous equivalent period.', $attention);
        if ($overall['orders'] === 0) $warnings[] = 'No completed orders were recorded in this period.';
        if ($salesChange < 0 || $profitChange < 0) $recommendations[] = 'Review declining revenue and profit branches and compare their service demand and operating costs.';
        if ($attention) $recommendations[] = sprintf('Investigate %s and create an action plan to improve sales.', $attention);
        if (! count($recommendations)) $recommendations[] = 'Continue monitoring branch revenue, profit, and completed orders against the previous equivalent period.';
        $fallback = [
            'status' => $score >= 70 ? 'Doing Well' : ($score >= 40 ? 'Needs Attention' : 'Critical'),
            'score' => $score,
            'summary' => sprintf('Revenue %s %.2f%% compared with the previous equivalent period.', $salesChange >= 0 ? 'increased by' : 'decreased by', abs($salesChange)),
            'positives' => $positives,
            'attention' => $warnings,
            'recommendations' => $recommendations,
            'best_branch' => $best,
            'branch_needing_attention' => $attention,
        ];
        $key = config('services.gemini.key');
        if (! $key) return array_merge($fallback, ['source' => 'rule_based']);
        try {
            $response = Http::timeout(config('services.gemini.timeout'))->post(rtrim(config('services.gemini.base_url'), '/') . '/models/' . config('services.gemini.model') . ':generateContent?key=' . urlencode($key), ['contents' => [['parts' => [['text' => 'Analyze these trusted laundry business metrics. Do not invent numbers. Return JSON with status, score (0-100), summary, positives (array), attention (array), recommendations (array), best_branch, branch_needing_attention. Metrics: ' . json_encode($metrics)]]]]]);
            $text = $response->json('candidates.0.content.parts.0.text');
            $text = preg_replace('/^```json|```$/m', '', (string) $text);
            $analysis = json_decode(trim($text), true);
            if (is_array($analysis) && isset($analysis['summary'])) return array_merge($fallback, $analysis, ['source' => 'gemini']);
        } catch (\Throwable $exception) { }
        return array_merge($fallback, ['source' => 'rule_based']);
    }

    /**
     * Get user's accessible warehouse IDs
     *
     * @param mixed $user
     * @param int|null $warehouseId
     * @return array
     */
    private function getUserWarehouseIds($user, ?int $warehouseId = null): array
    {
        if ($warehouseId) {
            return [$warehouseId];
        }

        if ($user && $user->is_all_warehouses) {
            return \App\Models\Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->toArray();
        }

        if ($user) {
            return \App\Models\UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        return [];
    }
}
