<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = JournalEntry::where('owner_id', $user->id)
            ->with(['lines.account', 'preparer', 'poster', 'voidedBy']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from')) {
            $query->where('date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('date', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $entries = $query->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'description' => 'required|string|max:500',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        foreach ($validated['lines'] as $line) {
            $account = Account::where('id', $line['account_id'])
                ->where('owner_id', $user->id)
                ->first();
            if (!$account) {
                return response()->json(['message' => 'Account not found'], 422);
            }
        }

        $totalDebit = array_sum(array_column($validated['lines'], 'debit'));
        $totalCredit = array_sum(array_column($validated['lines'], 'credit'));

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json(['message' => 'Debits and credits must be equal'], 422);
        }

        if ($totalDebit == 0) {
            return response()->json(['message' => 'Total must be greater than zero'], 422);
        }

        DB::beginTransaction();

        try {
            $entry = JournalEntry::create([
                'owner_id' => $user->id,
                'reference' => JournalEntry::generateReference($user->id),
                'date' => $validated['date'],
                'description' => $validated['description'],
                'status' => 'draft',
                'prepared_by' => $user->id,
            ]);

            foreach ($validated['lines'] as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            DB::commit();

            $entry->load('lines.account', 'preparer');

            return response()->json($entry, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create journal entry'], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->with(['lines.account', 'preparer', 'poster', 'voidedBy'])
            ->firstOrFail();

        $entry->append('total_debit', 'total_credit');

        return response()->json($entry);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $validated = $request->validate([
            'date' => 'sometimes|date',
            'description' => 'sometimes|string|max:500',
            'lines' => 'sometimes|array|min:2',
            'lines.*.account_id' => 'required_with:lines|exists:accounts,id',
            'lines.*.debit' => 'required_with:lines|numeric|min:0',
            'lines.*.credit' => 'required_with:lines|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            $updateData = array_filter($validated, fn($k) => $k !== 'lines', ARRAY_FILTER_USE_KEY);
            if ($updateData) {
                $entry->update($updateData);
            }

            if (isset($validated['lines'])) {
                $totalDebit = array_sum(array_column($validated['lines'], 'debit'));
                $totalCredit = array_sum(array_column($validated['lines'], 'credit'));

                if (abs($totalDebit - $totalCredit) > 0.01) {
                    DB::rollBack();
                    return response()->json(['message' => 'Debits and credits must be equal'], 422);
                }

                foreach ($validated['lines'] as $line) {
                    $account = Account::where('id', $line['account_id'])
                        ->where('owner_id', $user->id)
                        ->first();
                    if (!$account) {
                        DB::rollBack();
                        return response()->json(['message' => 'Account not found'], 422);
                    }
                }

                $entry->lines()->delete();

                foreach ($validated['lines'] as $line) {
                    $entry->lines()->create([
                        'account_id' => $line['account_id'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'description' => $line['description'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $entry->load('lines.account', 'preparer');

            return response()->json($entry);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update journal entry'], 500);
        }
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $entry->load('lines');

        if (!$entry->isBalanced()) {
            return response()->json(['message' => 'Journal entry is not balanced'], 422);
        }

        if ($entry->total_debit == 0) {
            return response()->json(['message' => 'Journal entry has no amounts'], 422);
        }

        $user = $request->user();

        $entry->update([
            'status' => 'posted',
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $entry->load('lines.account', 'poster');

        return response()->json([
            'entry' => $entry,
            'message' => 'Journal entry posted successfully',
        ]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $entry = JournalEntry::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $user = $request->user();

        $entry->update([
            'status' => 'voided',
            'voided_by' => $user->id,
            'voided_at' => now(),
            'void_reason' => $validated['reason'],
        ]);

        $entry->load('lines.account', 'voidedBy');

        return response()->json([
            'entry' => $entry,
            'message' => 'Journal entry voided',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entry = JournalEntry::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $entry->delete();

        return response()->json(['message' => 'Journal entry deleted']);
    }
}
