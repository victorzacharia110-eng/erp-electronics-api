<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AccountingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingIssuesController extends Controller
{
    public function __construct(
        private AccountingReportService $reportService = new AccountingReportService()
    ) {}

    /**
     * Actionable accounting issues for the current user.
     *
     * Employees see branch-scoped, non-financial counts only (no P&L / equity figures).
     * Owners see full business-level issues including amounts.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user->isOwner();

        if ($isOwner) {
            $owner = $user;
            $branchId = null;
        } else {
            $owner = $user->employeeProfile?->branch?->owner ?? $user;
            $branchId = $user->employeeProfile?->branch_id;
        }

        $issues = [];

        // 1. Payments that were made but not yet confirmed by an employee
        $unconfirmed = Order::where('status', 'pending')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('payments', fn ($q) => $q->where('status', 'completed'))
            ->count();

        if ($unconfirmed > 0) {
            $issues[] = [
                'type' => 'unconfirmed_payments',
                'severity' => 'high',
                'title_en' => 'Unconfirmed payments',
                'title_sw' => 'Malipo ambayo hayajathibitishwa',
                'description_en' => 'Customers paid but the payments have not been confirmed yet.',
                'description_sw' => 'Wateja wamelipa lakini malipo bado hayajathibitishwa.',
                'count' => $unconfirmed,
                'amount' => $isOwner
                    ? (float) Order::where('status', 'pending')
                        ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                        ->whereHas('payments', fn ($q) => $q->where('status', 'completed'))
                        ->sum('total')
                    : null,
            ];
        }

        // 2. Deliveries waiting to be shipped/delivered
        $pendingDelivery = Order::where('delivery_required', true)
            ->whereIn('status', ['paid', 'processing', 'shipped'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        if ($pendingDelivery > 0) {
            $issues[] = [
                'type' => 'pending_deliveries',
                'severity' => 'medium',
                'title_en' => 'Pending deliveries',
                'title_sw' => 'Usafirishaji unaosubiri',
                'description_en' => 'Paid orders still need to be processed, shipped, or delivered.',
                'description_sw' => 'Agizo lililolipwa bado linahitaji kusindikwa, kusafirishwa, au kufikishwa.',
                'count' => $pendingDelivery,
                'amount' => null,
            ];
        }

        // 3. Missing cost prices block accurate COGS
        $missingCost = ProductVariant::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('cost_price')->orWhere('cost_price', 0))
            ->count();

        if ($missingCost > 0) {
            $issues[] = [
                'type' => 'missing_cost_prices',
                'severity' => 'high',
                'title_en' => 'Products missing cost price',
                'title_sw' => 'Bidhaa zisizo na bei ya gharama',
                'description_en' => 'These products cannot be booked to COGS on sale. Set their cost prices.',
                'description_sw' => 'Bidhaa hizi haziwezi kuhesabiwa kwenye gharama ya bidhaa (COGS). Weka bei ya gharama.',
                'count' => $missingCost,
                'amount' => null,
            ];
        }

        // 4. Draft journal entries need review/post
        $draftEntries = JournalEntry::where('owner_id', $owner->id)->where('status', 'draft')->count();

        if ($draftEntries > 0) {
            $issues[] = [
                'type' => 'draft_entries',
                'severity' => 'medium',
                'title_en' => 'Draft journal entries',
                'title_sw' => 'Maingizo ya jarida yasiyotumwa',
                'description_en' => 'Draft journal entries have not been posted yet.',
                'description_sw' => 'Maingizo ya jarida (draft) bado hayajatuma.',
                'count' => $draftEntries,
                'amount' => null,
            ];
        }

        // 5. Voided entries may indicate data issues
        $voidedEntries = JournalEntry::where('owner_id', $owner->id)->where('status', 'voided')->count();

        if ($voidedEntries > 0) {
            $issues[] = [
                'type' => 'voided_entries',
                'severity' => 'low',
                'title_en' => 'Voided journal entries',
                'title_sw' => 'Maingizo ya jarida yaliyobatilishwa',
                'description_en' => 'Some journal entries were voided. Confirm the reasons are documented.',
                'description_sw' => 'Baadhi ya maingizo yalibatilishwa. Hakikisha sababu zimeandikwa.',
                'count' => $voidedEntries,
                'amount' => null,
            ];
        }

        // 6. Pending commissions
        $commissionQuery = Commission::where('owner_id', $owner->id)->where('status', 'pending');
        if (!$isOwner && $branchId) {
            $commissionQuery->whereHas('employee', fn ($q) => $q->whereHas('employeeProfile', fn ($q2) => $q2->where('branch_id', $branchId)));
        }
        $pendingCommissions = $commissionQuery->count();
        $pendingCommissionsTotal = $commissionQuery->sum('commission_amount');

        if ($pendingCommissions > 0) {
            $issues[] = [
                'type' => 'pending_commissions',
                'severity' => 'low',
                'title_en' => 'Pending commissions',
                'title_sw' => 'Tume zinazosubiri',
                'description_en' => 'Employee commissions have been earned but not yet paid out.',
                'description_sw' => 'Tume za wafanyakazi zimepatikana lakini bado hazijalipwa.',
                'count' => $pendingCommissions,
                'amount' => $isOwner ? (float) $pendingCommissionsTotal : null,
            ];
        }

        // 7. Unbalanced trial balance
        $trialBalance = $this->reportService->computeTrialBalance($owner, now()->toDateString());
        if (!$trialBalance['is_balanced']) {
            $issues[] = [
                'type' => 'unbalanced_trial_balance',
                'severity' => 'high',
                'title_en' => 'Trial balance is out of balance',
                'title_sw' => 'Mizani ya jaribio haifanani',
                'description_en' => 'Debits and credits do not match. Please review recent journal entries.',
                'description_sw' => 'Debit na credit hazifanani. Tafadhali kagua maingizo ya hivi karibuni.',
                'count' => 1,
                'amount' => $isOwner ? round(abs($trialBalance['total_debit'] - $trialBalance['total_credit']), 2) : null,
            ];
        }

        // 8. Low stock
        $lowStock = Inventory::whereHas('productVariant', fn ($q) => $q->where('is_active', true))
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->count();

        if ($lowStock > 0) {
            $issues[] = [
                'type' => 'low_stock',
                'severity' => 'medium',
                'title_en' => 'Low stock items',
                'title_sw' => 'Bidhaa za chini',
                'description_en' => 'Some products are at or below their reorder level.',
                'description_sw' => 'Baadhi ya bidhaa ziko chini au sawa na kiwango cha kuagiza.',
                'count' => $lowStock,
                'amount' => null,
            ];
        }

        return response()->json([
            'scope' => $isOwner ? 'owner' : ($branchId ? 'branch' : 'owner'),
            'issues' => $issues,
        ]);
    }
}
