<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function daily(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->toDateString());
        $carbon = Carbon::parse($date);

        $report = DailyReport::where('report_date', $carbon->toDateString())->first();

        if (!$report) {
            $report = $this->generateForDate($carbon);
        }

        return response()->json($report);
    }

    public function summary(Request $request): JsonResponse
    {
        $from = $request->query('from', Carbon::now()->subDays(30)->toDateString());
        $to = $request->query('to', Carbon::today()->toDateString());

        $reports = DailyReport::whereBetween('report_date', [$from, $to])
            ->orderBy('report_date', 'desc')
            ->get();

        $totals = [
            'total_orders' => $reports->sum('total_orders'),
            'total_revenue' => $reports->sum('total_revenue'),
            'total_items_sold' => $reports->sum('total_items_sold'),
            'paid_orders' => $reports->sum('paid_orders'),
            'pending_orders' => $reports->sum('pending_orders'),
            'cancelled_orders' => $reports->sum('cancelled_orders'),
        ];

        return response()->json([
            'reports' => $reports,
            'totals' => $totals,
        ]);
    }

    public function generateForDate(Carbon $date): DailyReport
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $orders = Order::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', '!=', 'pending_payment')
            ->with(['items.productVariant.product', 'handler'])
            ->get();

        $totalOrders = $orders->count();
        $totalRevenue = $orders->where('status', 'paid')->sum('total');
        $totalItemsSold = $orders->where('status', 'paid')->sum(fn($o) => $o->items->sum('quantity'));
        $paidOrders = $orders->where('status', 'paid')->count();
        $pendingOrders = $orders->whereIn('status', ['processing', 'shipped'])->count();
        $cancelledOrders = $orders->where('status', 'cancelled')->count();

        $employeeStats = $orders->where('status', 'paid')
            ->filter(fn($o) => $o->handler)
            ->groupBy(fn($o) => $o->handler->id)
            ->map(function ($empOrders, $empId) {
                $handler = $empOrders->first()->handler;
                return [
                    'name' => $handler->name,
                    'email' => $handler->email,
                    'orders_handled' => $empOrders->count(),
                    'revenue_collected' => round($empOrders->sum('total'), 2),
                ];
            })
            ->values()
            ->toArray();

        $topProducts = $orders->where('status', 'paid')
            ->flatMap(fn($o) => $o->items)
            ->groupBy(fn($item) => $item->productVariant->product->name ?? 'Unknown')
            ->map(function ($items, $name) {
                return [
                    'name' => $name,
                    'quantity_sold' => $items->sum('quantity'),
                    'revenue' => round($items->sum('total'), 2),
                ];
            })
            ->sortByDesc('quantity_sold')
            ->values()
            ->take(10)
            ->toArray();

        $report = DailyReport::updateOrCreate(
            ['report_date' => $date->toDateString()],
            [
                'total_orders' => $totalOrders,
                'total_revenue' => round($totalRevenue, 2),
                'total_items_sold' => (int) $totalItemsSold,
                'paid_orders' => $paidOrders,
                'pending_orders' => $pendingOrders,
                'cancelled_orders' => $cancelledOrders,
                'employee_stats' => $employeeStats,
                'top_products' => $topProducts,
            ]
        );

        return $report;
    }
}
