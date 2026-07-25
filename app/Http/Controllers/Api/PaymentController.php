<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'provider' => 'required|in:airtel,mixx_by_yas,mpesa,halopesa,clickpesa,cash',
            'phone_number' => 'nullable|string',
        ]);

        $order = Order::where('id', $validated['order_id'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'pending_payment'])
            ->firstOrFail();

        $isClickpesa = $validated['provider'] === 'clickpesa';
        $isCash = $validated['provider'] === 'cash';

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => $validated['provider'],
            'amount' => $order->total,
            'status' => 'pending',
            'metadata' => array_filter(['phone_number' => $validated['phone_number'] ?? null]),
        ]);

        if ($isClickpesa || $isCash) {
            $payment->update(['status' => 'completed']);
            $order->update(['handled_by' => $request->user()->id]);
            $order->markAsPaid();
        }

        $message = match (true) {
            $isClickpesa => 'Payment confirmed via ClickPesa. Thank you for your order!',
            $isCash => 'Cash payment confirmed. Order complete!',
            default => 'Payment recorded. An employee will confirm your payment shortly.',
        };

        return response()->json([
            'payment' => $payment,
            'message' => $message,
            'order_number' => $order->order_number,
            'amount' => $order->total,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        // TODO: Validate webhook signature from payment gateway
        $data = $request->all();

        $providerReference = $data['provider_reference'] ?? null;
        $status = $data['status'] ?? null;

        if (!$providerReference) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $payment = Payment::where('provider_reference', $providerReference)->firstOrFail();

        if ($status === 'completed') {
            $payment->update(['status' => 'completed']);
            $payment->order->markAsPaid();
        } else {
            $payment->update(['status' => 'failed']);
        }

        return response()->json(['message' => 'Webhook processed']);
    }

    public function status(Request $request, string $orderId): JsonResponse
    {
        $payment = Payment::where('order_id', $orderId)
            ->where('order.user_id', $request->user()->id)
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->latest()
            ->first();

        return response()->json($payment ?? ['status' => 'no_payment']);
    }
}
