<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = SupportMessage::with(['order', 'user']);

        if ($user->isCustomer()) {
            $query->where('user_id', $user->id);
        }

        $status = $request->query('status');
        if ($status) {
            $query->where('status', $status);
        }

        $messages = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'category' => 'required|in:payment_issue,order_status,delivery,refund,general',
            'order_id' => 'nullable|exists:orders,id',
        ]);

        if ($validated['order_id']) {
            $order = \App\Models\Order::where('id', $validated['order_id'])
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }
        }

        $msg = SupportMessage::create([
            'user_id' => $request->user()->id,
            'order_id' => $validated['order_id'] ?? null,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'category' => $validated['category'],
        ]);

        return response()->json($msg->load(['order']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $msg = SupportMessage::with(['order', 'user'])->findOrFail($id);

        if ($request->user()->isCustomer() && $msg->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($msg);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'admin_reply' => 'required|string|max:2000',
            'status' => 'required|in:in_progress,resolved,closed',
        ]);

        $msg = SupportMessage::findOrFail($id);
        $msg->update([
            'admin_reply' => $validated['admin_reply'],
            'status' => $validated['status'],
            'replied_at' => now(),
        ]);

        return response()->json($msg->fresh(['order', 'user']));
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $msg = SupportMessage::findOrFail($id);
        $msg->update(['status' => $validated['status']]);

        return response()->json($msg->fresh(['order', 'user']));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = SupportMessage::where('status', 'open')->count();
        $repliesCount = 0;

        if ($request->user()->isCustomer()) {
            $repliesCount = SupportMessage::where('user_id', $request->user()->id)
                ->whereNotNull('admin_reply')
                ->where('status', '!=', 'closed')
                ->count();
        }

        return response()->json([
            'open_tickets' => $request->user()->isCustomer() ? 0 : $count,
            'pending_replies' => $repliesCount,
        ]);
    }
}
