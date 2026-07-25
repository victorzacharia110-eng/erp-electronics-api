<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Conversation::with(['owner', 'customer', 'superadmin', 'lastMessage.sender']);

        if ($user->isSuperadmin()) {
            $query->where('type', 'superadmin_owner');
        } elseif ($user->isOwner()) {
            // Owner sees both types
        } elseif ($user->isCustomer()) {
            $query->where('type', 'customer_owner')->where('customer_id', $user->id);
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
            $validated['owner_id'] = $user->id;
            $validated['type'] = 'customer_owner';
        } elseif ($user->isCustomer()) {
            $validated['customer_id'] = $user->id;
            $validated['owner_id'] = $validated['owner_id'] ?? User::where('role', 'owner')->first()?->id;
            $validated['type'] = 'customer_owner';
        } elseif ($user->isSuperadmin()) {
            $validated['type'] = 'superadmin_owner';
        }

        $conversation = Conversation::create([
            'type' => $validated['type'],
            'owner_id' => $validated['owner_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'superadmin_id' => $user->isSuperadmin() ? $user->id : null,
            'subject' => $validated['subject'],
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $conversation->messages()->create([
            'sender_id' => $user->id,
            'message' => $validated['message'],
        ]);

        return response()->json($conversation->fresh(['owner', 'customer', 'superadmin', 'messages.sender']), 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->isOwner() && $conversation->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($user->isCustomer() && $conversation->customer_id !== $user->id) {
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

        if ($user->isOwner() && $conversation->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($user->isCustomer() && $conversation->customer_id !== $user->id) {
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
            $conversations = Conversation::where('owner_id', $user->id)
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
}
