<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Account::where('owner_id', $user->id)->with('parent');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($request->boolean('with_children')) {
            $query->with('children');
        }

        $accounts = $query->orderBy('code')->get();

        $accounts->each(function ($account) {
            $account->append('balance', 'formatted_code');
        });

        return response()->json($accounts);
    }

    public function tree(Request $request): JsonResponse
    {
        $user = $request->user();
        $accounts = Account::where('owner_id', $user->id)
            ->whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->orderBy('code');
            }])
            ->orderBy('code')
            ->get();

        $accounts->each(function ($account) {
            $account->append('formatted_code');
            $account->children->each(function ($child) {
                $child->append('balance', 'formatted_code');
            });
        });

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'normal_balance' => 'required|in:debit,credit',
            'parent_id' => 'nullable|exists:accounts,id',
            'description' => 'nullable|string',
        ]);

        $user = $request->user();

        $exists = Account::where('owner_id', $user->id)
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Account code already exists'], 422);
        }

        if ($validated['parent_id']) {
            $parent = Account::where('id', $validated['parent_id'])
                ->where('owner_id', $user->id)
                ->firstOrFail();
        }

        $account = Account::create([
            ...$validated,
            'owner_id' => $user->id,
        ]);

        $account->append('balance', 'formatted_code');

        return response()->json($account, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = Account::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        if ($account->is_system) {
            return response()->json(['message' => 'System accounts cannot be modified'], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $account->update($validated);
        $account->append('balance', 'formatted_code');

        return response()->json($account);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = Account::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        if ($account->is_system) {
            return response()->json(['message' => 'System accounts cannot be deleted'], 422);
        }

        if ($account->journalLines()->count() > 0) {
            return response()->json(['message' => 'Account has transactions and cannot be deleted'], 422);
        }

        if ($account->children()->count() > 0) {
            return response()->json(['message' => 'Account has sub-accounts and cannot be deleted'], 422);
        }

        $account->delete();

        return response()->json(['message' => 'Account deleted']);
    }
}
