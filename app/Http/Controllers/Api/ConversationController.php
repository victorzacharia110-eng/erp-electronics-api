<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Conversation::with(['owner', 'customer', 'superadmin', 'lastMessage.sender']);

        if ($user->isSuperadmin()) {
            $query->where('type', 'superadmin_owner');
        } elseif ($user->isOwner()) {
            $query->where('owner_id', $request->ownerId() ?? $user->id);
        } elseif ($user->isCustomer()) {
            $query->where('type', 'customer_owner')->where('customer_id', $user->id);
        } else {
            // Employees/suppliers use the support-messages system, not conversations
            $query->whereRaw('1 = 0');
        }

        $status = $request->query('status');
        if ($status) {
            $query->where('status', $status);
        }

        $type = $request->query('type');
        if ($type && $user->isOwner()) {
            $query->where('type', $type);
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        $conversations = $query->orderBy('last_message_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($conversations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'type' => 'required|in:superadmin_owner,customer_owner',
            'owner_id' => 'required_without:customer_id|nullable|exists:users,id',
            'customer_id' => 'required_without:owner_id|nullable|exists:users,id',
        ]);

        $user = $request->user();

        if ($user->isOwner()) {
            $validated['owner_id'] = $request->ownerId() ?? $user->id;
            $validated['type'] = 'customer_owner';

            // Owners can only message customers who ordered from their store
            if (!empty($validated['customer_id'])) {
                $customerKnown = Order::query()
                    ->where('user_id', $validated['customer_id'])
                    ->where(function ($q) use ($validated) {
                        $q->whereHas('branch', fn ($qb) => $qb->where('owner_id', $validated['owner_id']))
                            ->orWhereNull('branch_id');
                    })
                    ->exists();
                if (!$customerKnown) {
                    return response()->json(['message' => 'You can only message customers who have ordered from your store.'], 403);
                }
            }
        } elseif ($user->isCustomer()) {
            $validated['customer_id'] = $user->id;
            $validated['owner_id'] = $validated['owner_id'] ?? User::where('role', 'owner')->first()?->id;
            $validated['type'] = 'customer_owner';
        } elseif ($user->isSuperadmin()) {
            $validated['type'] = 'superadmin_owner';
        }

        // Reuse an existing open conversation with the same parties instead of duplicating threads
        $conversation = Conversation::query()
            ->where('type', $validated['type'])
            ->where('owner_id', $validated['owner_id'])
            ->where('customer_id', $validated['customer_id'] ?? null)
            ->where('status', '!=', 'closed')
            ->orderByDesc('id')
            ->first();

        $created = false;
        if (!$conversation) {
            $conversation = Conversation::create([
                'type' => $validated['type'],
                'owner_id' => $validated['owner_id'],
                'customer_id' => $validated['customer_id'] ?? null,
                'superadmin_id' => $user->isSuperadmin() ? $user->id : null,
                'subject' => $validated['subject'],
                'status' => 'open',
                'last_message_at' => now(),
            ]);
            $created = true;
        }

        $conversation->messages()->create([
            'sender_id' => $user->id,
            'message' => $validated['message'],
        ]);

        $conversation->update(['last_message_at' => now(), 'status' => 'open']);

        return response()->json($conversation->fresh(['owner', 'customer', 'superadmin', 'messages.sender']), $created ? 201 : 200);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->isOwner() && $conversation->owner_id !== ($request->ownerId() ?? $user->id)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($user->isCustomer() && $conversation->customer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($user->isSuperadmin() && $conversation->type !== 'superadmin_owner') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $conversation->load(['owner', 'customer', 'superadmin', 'messages.sender']);

        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json($conversation);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->isOwner() && $conversation->owner_id !== ($request->ownerId() ?? $user->id)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($user->isCustomer() && $conversation->customer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($user->isSuperadmin() && $conversation->type !== 'superadmin_owner') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $msg = $conversation->messages()->create([
            'sender_id' => $user->id,
            'message' => $validated['message'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        if ($user->isSuperadmin() && !$conversation->superadmin_id) {
            $conversation->update(['superadmin_id' => $user->id]);
        }
        if ($conversation->status === 'closed') {
            $conversation->update(['status' => 'open']);
        }

        return response()->json($msg->load('sender'), 201);
    }

    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $conversation->update(['status' => $validated['status']]);

        return response()->json($conversation->fresh(['owner', 'customer', 'superadmin']));
    }

    public function ownerDetails(Conversation $conversation): JsonResponse
    {
        $owner = $conversation->owner;
        $profile = $owner->ownerProfile;
        $branch = $owner->branches()->first();

        return response()->json([
            'full_name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'company_name' => $profile?->brand_store_name ?? $owner->name . "'s Store",
            'plan' => $profile?->subscription_plan ?? 'N/A',
            'subscription_status' => $profile?->subscription_status ?? 'N/A',
            'branch_name' => $branch?->name ?? 'Main Store',
            'city' => $branch?->city ?? null,
            'address' => $branch?->address ?? null,
            'phone_number' => $owner->phone ?? $branch?->phone ?? 'N/A',
            'location' => collect([$branch?->city, $branch?->address])->filter()->implode(', ') ?: 'N/A',
        ]);
    }

    public function customerDetails(Conversation $conversation): JsonResponse
    {
        $customer = $conversation->customer;
        $address = $customer->addresses()->first();

        return response()->json([
            'full_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone ?? 'N/A',
            'location' => $address ? collect([$address->city, $address->street])->filter()->implode(', ') : 'N/A',
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = 0;

        if ($user->isSuperadmin()) {
            $conversations = Conversation::where('type', 'superadmin_owner')
                ->where('status', '!=', 'closed')
                ->get();
        } elseif ($user->isOwner()) {
            $conversations = Conversation::where('owner_id', $request->ownerId() ?? $user->id)
                ->where('status', '!=', 'closed')
                ->get();
        } elseif ($user->isCustomer()) {
            $conversations = Conversation::where('type', 'customer_owner')
                ->where('customer_id', $user->id)
                ->where('status', '!=', 'closed')
                ->get();
        } else {
            return response()->json(['unread_count' => 0]);
        }

        foreach ($conversations as $conv) {
            $hasUnread = $conv->messages()
                ->where('sender_id', '!=', $user->id)
                ->where('is_read', false)
                ->exists();
            if ($hasUnread) {
                $count++;
            }
        }

        return response()->json(['unread_count' => $count]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperadmin()) {
            $list = User::where('role', 'owner')
                ->with('ownerProfile')
                ->orderBy('name')
                ->get();

            $contacts = $list->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => 'owner',
                'store_name' => $u->ownerProfile?->brand_store_name ?? $u->name,
                'is_active' => $u->is_active,
                'avatar_text' => Str::upper(Str::substr($u->name, 0, 1)),
            ]);
        } elseif ($user->isOwner()) {
            $ownerId = $request->ownerId() ?? $user->id;

            $customerIds = Order::query()
                ->where(function ($q) use ($ownerId) {
                    $q->whereHas('branch', fn ($qb) => $qb->where('owner_id', $ownerId))
                        ->orWhereNull('branch_id');
                })
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');

            $list = User::where('role', 'customer')
                ->whereIn('id', $customerIds)
                ->orderBy('name')
                ->get();

            $contacts = $list->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => 'customer',
                'store_name' => null,
                'is_active' => $u->is_active,
                'avatar_text' => Str::upper(Str::substr($u->name, 0, 1)),
            ]);
        } else {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $contacts->values()]);
    }
}
