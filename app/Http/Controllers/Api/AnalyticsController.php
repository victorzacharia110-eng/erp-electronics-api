<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AnalyticsController extends Controller
{
    public function sales(Request $request): JsonResponse
    {
        $months = (int) $request->query('months', 12);
        $startDate = Carbon::now()->subMonths($months - 1)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();
        $ownerId = $request->ownerId();

        $monthlySales = Order::where('orders.status', 'paid')
            ->whereBetween('orders.created_at', [$startDate, $endDate])->tap(fn($q) => $q->when($ownerId, fn($qb) => $qb->whereHas('branch', fn($bc) => $bc->where('owner_id', $ownerId))))
            ->select(
                DB::raw("strftime('%Y-%m', orders.created_at) as month"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(orders.total) as revenue'),
                DB::raw('SUM(orders.shipping_cost) as shipping_revenue'),
                DB::raw('SUM(orders.subtotal) as product_revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $monthlyItems = Order::where('orders.status', 'paid')
            ->whereBetween('orders.created_at', [$startDate, $endDate])->tap(fn($q) => $q->when($ownerId, fn($qb) => $qb->whereHas('branch', fn($bc) => $bc->where('owner_id', $ownerId))))
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->select(
                DB::raw("strftime('%Y-%m', orders.created_at) as month"),
                DB::raw('SUM(order_items.quantity) as items_sold'),
                DB::raw('SUM(order_items.total) as item_revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $monthlyProfit = Order::where('orders.status', 'paid')
            ->whereBetween('orders.created_at', [$startDate, $endDate])->tap(fn($q) => $q->when($ownerId, fn($qb) => $qb->whereHas('branch', fn($bc) => $bc->where('owner_id', $ownerId))))
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->select(
                DB::raw("strftime('%Y-%m', orders.created_at) as month"),
                DB::raw('SUM(order_items.total) as revenue'),
                DB::raw('SUM(order_items.quantity * product_variants.cost_price) as cost'),
                DB::raw('SUM(order_items.total - order_items.quantity * product_variants.cost_price) as profit')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $monthlyCancelled = Order::where('orders.status', 'cancelled')
            ->whereBetween('orders.created_at', [$startDate, $endDate])->tap(fn($q) => $q->when($ownerId, fn($qb) => $qb->whereHas('branch', fn($bc) => $bc->where('owner_id', $ownerId))))
            ->select(
                DB::raw("strftime('%Y-%m', orders.created_at) as month"),
                DB::raw('COUNT(*) as cancelled_count'),
                DB::raw('SUM(orders.total) as lost_revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $categoryBreakdown = Order::where('orders.status', 'paid')
            ->whereBetween('orders.created_at', [$startDate, $endDate])->tap(fn($q) => $q->when($ownerId, fn($qb) => $qb->whereHas('branch', fn($bc) => $bc->where('owner_id', $ownerId))))
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'categories.name as category',
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.total) as revenue')
            )
            ->groupBy('categories.name')
            ->orderByDesc('revenue')
            ->get();

        $topProducts = Order::where('orders.status', 'paid')
            ->whereBetween('orders.created_at', [$startDate, $endDate])->tap(fn($q) => $q->when($ownerId, fn($qb) => $qb->whereHas('branch', fn($bc) => $bc->where('owner_id', $ownerId))))
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'products.name as product_name',
                'products.brand',
                'categories.name as category',
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.total) as revenue'),
                DB::raw('AVG(order_items.unit_price) as avg_price')
            )
            ->groupBy('products.id', 'products.name', 'products.brand', 'categories.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $allMonths = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $allMonths[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        $salesByMonth = collect($allMonths)->map(function ($m) use ($monthlySales, $monthlyItems, $monthlyProfit, $monthlyCancelled) {
            $sale = $monthlySales->firstWhere('month', $m);
            $item = $monthlyItems->firstWhere('month', $m);
            $profit = $monthlyProfit->firstWhere('month', $m);
            $cancelled = $monthlyCancelled->firstWhere('month', $m);
            return [
                'month' => $m,
                'label' => Carbon::parse($m . '-01')->format('M Y'),
                'order_count' => $sale['order_count'] ?? 0,
                'revenue' => (float) ($sale['revenue'] ?? 0),
                'shipping_revenue' => (float) ($sale['shipping_revenue'] ?? 0),
                'product_revenue' => (float) ($sale['product_revenue'] ?? 0),
                'items_sold' => (int) ($item['items_sold'] ?? 0),
                'profit' => (float) ($profit['profit'] ?? 0),
                'cost' => (float) ($profit['cost'] ?? 0),
                'cancelled_count' => (int) ($cancelled['cancelled_count'] ?? 0),
                'lost_revenue' => (float) ($cancelled['lost_revenue'] ?? 0),
            ];
        })->toArray();

        $totalRevenue = collect($salesByMonth)->sum('revenue');
        $totalProfit = collect($salesByMonth)->sum('profit');
        $totalOrders = collect($salesByMonth)->sum('order_count');
        $totalItems = collect($salesByMonth)->sum('items_sold');
        $avgOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

        $currentMonth = Carbon::now()->format('Y-m');
        $lastMonth = Carbon::now()->subMonth()->format('Y-m');
        $currentData = collect($salesByMonth)->firstWhere('month', $currentMonth) ?? ['revenue' => 0, 'order_count' => 0, 'profit' => 0];
        $lastData = collect($salesByMonth)->firstWhere('month', $lastMonth) ?? ['revenue' => 0, 'order_count' => 0, 'profit' => 0];

        $revenueGrowth = $lastData['revenue'] > 0 ? round((($currentData['revenue'] - $lastData['revenue']) / $lastData['revenue']) * 100, 1) : 0;
        $orderGrowth = $lastData['order_count'] > 0 ? round((($currentData['order_count'] - $lastData['order_count']) / $lastData['order_count']) * 100, 1) : 0;

        return response()->json([
            'monthly' => $salesByMonth,
            'category_breakdown' => $categoryBreakdown,
            'top_products' => $topProducts,
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_profit' => round($totalProfit, 2),
                'total_orders' => $totalOrders,
                'total_items_sold' => $totalItems,
                'avg_order_value' => $avgOrderValue,
                'profit_margin' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0,
                'revenue_growth' => $revenueGrowth,
                'order_growth' => $orderGrowth,
            ],
        ]);
    }

    public function aiSuggestions(Request $request): JsonResponse
    {
        $request->validate([
            'analytics' => 'required|array',
        ]);

        $analytics = $request->input('analytics');

        $prompt = $this->buildPrompt($analytics);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(60)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . config('services.gemini.key'),
                [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 2048,
                    ],
                ]
            );

            if ($response->failed()) {
                return response()->json([
                    'suggestions' => $this->getFallbackSuggestions($analytics),
                    'source' => 'fallback',
                ]);
            }

            $body = $response->json();
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $suggestions = $this->parseSuggestions($text, $analytics);

            return response()->json([
                'suggestions' => $suggestions,
                'source' => 'ai',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'suggestions' => $this->getFallbackSuggestions($analytics),
                'source' => 'fallback',
            ]);
        }
    }

    private function buildPrompt(array $analytics): string
    {
        $summary = $analytics['summary'] ?? [];
        $monthly = $analytics['monthly'] ?? [];
        $categories = $analytics['category_breakdown'] ?? [];
        $topProducts = $analytics['top_products'] ?? [];

        $recentMonths = array_slice($monthly, -3);
        $monthlyText = '';
        foreach ($recentMonths as $m) {
            $monthlyText .= '- ' . $m['label'] . ': ' . $m['order_count'] . ' orders, TSh ' . number_format($m['revenue']) . ' revenue, TSh ' . number_format($m['profit']) . ' profit, ' . $m['items_sold'] . " items sold\n";
        }

        $categoryText = '';
        foreach ($categories as $c) {
            $categoryText .= '- ' . $c['category'] . ': ' . $c['quantity_sold'] . ' items sold, TSh ' . number_format($c['revenue']) . " revenue\n";
        }

        $productText = '';
        foreach (array_slice($topProducts, 0, 5) as $p) {
            $brand = $p['brand'] ?? 'N/A';
            $productText .= '- ' . $p['product_name'] . ' (' . $brand . ', ' . $p['category'] . '): ' . $p['quantity_sold'] . ' sold, TSh ' . number_format($p['revenue']) . ' revenue, avg price TSh ' . number_format($p['avg_price']) . "\n";
        }

        $tr = 'TSh';
        $mr = number_format($summary['total_revenue'] ?? 0);
        $mp = number_format($summary['total_profit'] ?? 0);
        $to = $summary['total_orders'] ?? 0;
        $ti = $summary['total_items_sold'] ?? 0;
        $ao = number_format($summary['avg_order_value'] ?? 0);
        $pm = $summary['profit_margin'] ?? 0;
        $rg = $summary['revenue_growth'] ?? 0;
        $og = $summary['order_growth'] ?? 0;

        $prompt = "You are a business analyst specializing in electronics retail in East Africa, particularly Tanzania. Analyze the following sales data for an electronics devices and accessories shop and provide actionable business suggestions.\n\n";
        $prompt .= "SALES DATA SUMMARY (Last 12 months):\n";
        $prompt .= "- Total Revenue: {$tr} {$mr}\n";
        $prompt .= "- Total Profit: {$tr} {$mp}\n";
        $prompt .= "- Total Orders: {$to}\n";
        $prompt .= "- Total Items Sold: {$ti}\n";
        $prompt .= "- Average Order Value: {$tr} {$ao}\n";
        $prompt .= "- Profit Margin: {$pm}%\n";
        $prompt .= "- Revenue Growth (vs last month): {$rg}%\n";
        $prompt .= "- Order Growth (vs last month): {$og}%\n\n";
        $prompt .= "RECENT MONTHLY PERFORMANCE:\n{$monthlyText}\n";
        $prompt .= "CATEGORY BREAKDOWN:\n{$categoryText}\n";
        $prompt .= "TOP PRODUCTS:\n{$productText}\n";
        $prompt .= "Provide 5-7 specific, actionable suggestions in JSON format. Each suggestion MUST have both Swahili and English versions.\n";
        $prompt .= '- "title_sw": Title ya Kiswahili (max 50 chars)' . "\n";
        $prompt .= '- "title_en": English title (max 50 chars)' . "\n";
        $prompt .= '- "description_sw": Maelezo ya Kiswahili (2-3 sentensi)' . "\n";
        $prompt .= '- "description_en": English description (2-3 sentences)' . "\n";
        $prompt .= '- "priority": "high", "medium", or "low"' . "\n";
        $prompt .= '- "category": one of "inventory", "pricing", "marketing", "operations", "growth"' . "\n\n";
        $prompt .= "For Swahili: use professional business terms (mapato, faida, gharama, bidhaa, wateja, soko, uuzaji, ununuzi, mtaji, hasara).\n";
        $prompt .= "Focus on:\n";
        $prompt .= "1. Which products to stock more of and which to discontinue\n";
        $prompt .= "2. Pricing optimization based on margins\n";
        $prompt .= "3. Marketing strategies for the Tanzanian electronics market\n";
        $prompt .= "4. Seasonal trends and preparation\n";
        $prompt .= "5. Customer retention and growth strategies\n";
        $prompt .= "6. Cost reduction opportunities\n";
        $prompt .= "7. Expansion opportunities (online, regional)\n\n";
        $prompt .= "Consider Tanzania-specific factors: mobile money dominance (M-Pesa, Airtel Money), import costs, seasonal patterns (school terms, holidays like Christmas and Eid), competitive landscape with Chinese electronics brands, and the growing young population.\n\n";
        $prompt .= 'Return ONLY valid JSON array, no markdown formatting: [{"title_sw": "...", "title_en": "...", "description_sw": "...", "description_en": "...", "priority": "high/medium/low", "category": "..."}]';

        return $prompt;
    }

    private function parseSuggestions(string $text, array $analytics): array
    {
        $text = trim($text);
        $cleaned = '';
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            if ($i + 2 < $len && $text[$i] === '`' && $text[$i + 1] === '`' && $text[$i + 2] === '`') {
                $i += 3;
                while ($i < $len && $text[$i] !== '`') {
                    $i++;
                }
                if ($i < $len) $i++;
                continue;
            }
            $cleaned .= $text[$i];
            $i++;
        }
        $text = trim($cleaned);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return array_map(function ($s) {
                return [
                    'title_sw' => $s['title_sw'] ?? $s['title'] ?? '',
                    'title_en' => $s['title_en'] ?? $s['title'] ?? '',
                    'description_sw' => $s['description_sw'] ?? $s['description'] ?? '',
                    'description_en' => $s['description_en'] ?? $s['description'] ?? '',
                    'priority' => $s['priority'] ?? 'medium',
                    'category' => $s['category'] ?? 'general',
                ];
            }, $decoded);
        }

        return $this->getFallbackSuggestions($analytics);
    }

    private function getFallbackSuggestions(array $analytics): array
    {
        $summary = $analytics['summary'] ?? [];
        $categories = $analytics['category_breakdown'] ?? [];
        $topProducts = $analytics['top_products'] ?? [];

        $suggestions = [];

        $margin = $summary['profit_margin'] ?? 0;
        if ($margin < 20) {
            $suggestions[] = [
                'title_sw' => 'Boresha Viwango vya Faida',
                'title_en' => 'Improve Profit Margins',
                'description_sw' => "Viwango vya faida vilivyopo ni {$margin}%, ambavyo ni chini ya shabaha ya 25-35% kwa biashara ya elektroniki. Fikiria kupunguza bei ya ununuzi kwa wauzaji, kuzingatia bidhaa za ufundi zenye faida kubwa, au kurekebisha bei za bidhaa zinazouzwa polepole.",
                'description_en' => "Your current profit margin is {$margin}%, which is below the 25-35% target for electronics retail. Consider negotiating better prices with suppliers, focusing on higher-margin accessories, or adjusting pricing on slow-moving items.",
                'priority' => 'high',
                'category' => 'pricing',
            ];
        }

        $growth = $summary['revenue_growth'] ?? 0;
        if ($growth < 0) {
            $suggestions[] = [
                'title_sw' => 'Mapato Yanashuka',
                'title_en' => 'Revenue Declining',
                'description_sw' => "Mapato yamepungua {$growth}% kutoka mwezi uliopita. Angalia upya mikakati ya uuzaji, angalia kama wapinzani wamezindua matangazo, na fikiria kuzindua kampeni ya M-Pesa cashback kuvuta wateja.",
                'description_en' => "Revenue dropped {$growth}% from last month. Review your marketing efforts, check if competitors have launched promotions, and consider running a targeted M-Pesa cashback campaign to attract customers.",
                'priority' => 'high',
                'category' => 'marketing',
            ];
        } else {
            $suggestions[] = [
                'title_sw' => 'Tumia Fursa ya Ukuaji',
                'title_en' => 'Capitalize on Growth',
                'description_sw' => "Mapato yamekuwa {$growth}% mwezi huu. Fikiria kutumia tena mtaji katika bidhaa zinazofanya vizuri na kupanua aina ya bidhaa ili kudumisha kasi.",
                'description_en' => "Revenue grew {$growth}% this month. Consider reinvesting in inventory for your top-performing categories and expanding your product range to maintain momentum.",
                'priority' => 'medium',
                'category' => 'growth',
            ];
        }

        if (!empty($topProducts)) {
            $topProduct = $topProducts[0];
            $suggestions[] = [
                'title_sw' => 'Tangaza Bidhaa Bora: ' . $topProduct['product_name'],
                'title_en' => 'Promote Top Seller: ' . $topProduct['product_name'],
                'description_sw' => "'" . $topProduct['product_name'] . "' ni bidhaa yako bora yenye vitu {$topProduct['quantity_sold']} vilivyouzwa. tengeneza mapato ya mkataba na bidhaa husika na ionyeshe katika duka na orodha za mtandaoni.",
                'description_en' => "'{$topProduct['product_name']}' is your best seller with {$topProduct['quantity_sold']} units sold. Create bundle deals with complementary accessories and feature it prominently in your store and online listings.",
                'priority' => 'medium',
                'category' => 'marketing',
            ];
        }

        $suggestions[] = [
            'title_sw' => 'Tumia Promosheni za M-Pesa Msimu',
            'title_en' => 'Leverage Mobile Money Seasonal Promos',
            'description_sw' => "Mauzo ya elektroniki nchini Tanzania yanaongezeka wakati wa msimu wa shule (Januari, Septemba) na sikukuu (Krismasi, Eid). Tayarisha bidhaa mapema na toa mipango ya malipo ya M-Pesa (lipa polepole) ili kuongeza mauzo wakati wa mahitaji makubwa.",
            'description_en' => "Tanzania electronics sales peak during back-to-school season (January, September) and holidays (Christmas, Eid). Prepare inventory early and offer M-Pesa installment plans (lipa polepole) to boost sales during high-demand periods.",
            'priority' => 'high',
            'category' => 'growth',
        ];

        if (!empty($categories)) {
            $lowCategory = end($categories);
            $suggestions[] = [
                'title_sw' => 'Angalia ' . $lowCategory['category'] . ' - Inafanya Vibaya',
                'title_en' => 'Review Underperforming: ' . $lowCategory['category'],
                'description_sw' => "'" . $lowCategory['category'] . "' yenye mapato ya chini TSh " . number_format($lowCategory['revenue']) . ". Fikiria ama kuiondoa, kuweka bei ya kuuza haraka, au kuitangaza zaidi.",
                'description_en' => "'{$lowCategory['category']}' has the lowest revenue at TSh " . number_format($lowCategory['revenue']) . ". Consider whether to discontinue, discount to clear stock, or market more aggressively.",
                'priority' => 'medium',
                'category' => 'inventory',
            ];
        }

        $suggestions[] = [
            'title_sw' => 'Boresha Viwango vya Hifadhi',
            'title_en' => 'Optimize Inventory Levels',
            'description_sw' => "Weka tahadhari za kununua upya kwa bidhaa zinazokimbia kwa kasi kulingana na kasi ya mauzo. Lenga siku 2-4 za bidhaa kwa bidhaa maarufu ili kuepuka kukosa bidhaa na kupunguza gharama za uhifadhi.",
            'description_en' => "Implement reorder alerts for fast-moving items based on your sales velocity. Aim for 2-4 weeks of stock for popular items to avoid stockouts while minimizing storage costs.",
            'priority' => 'medium',
            'category' => 'inventory',
        ];

        $suggestions[] = [
            'title_sw' => 'Panua Uwepo wa Mtandaoni',
            'title_en' => 'Expand Online Presence',
            'description_sw' => "Fikiria kuuza kwenye Jumia Tanzania, Takealot, au kuunda orodha ya WhatsApp Business. Wateja wengi wa Tanzania wanatafuta vifaa vya elektroniki mtandaoni kabla ya kununua dukani. Hii inaweza kuongeja trafiki ya miguu na maagizo ya mtandaoni.",
            'description_en' => "Consider selling on Jumia Tanzania, Takealot, or creating a WhatsApp Business catalog. Many Tanzanian customers research electronics online before buying in-store. This can drive foot traffic and online orders.",
            'priority' => 'low',
            'category' => 'growth',
        ];

        return $suggestions;
    }
}
