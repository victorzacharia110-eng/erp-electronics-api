<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnerProfile;
use App\Models\Setting;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public const DEFAULT_PLANS = [
        'free' => [
            'price_monthly' => 0,
            'max_products' => 10,
            'max_employees' => 2,
        ],
        'starter' => [
            'price_monthly' => 25000,
            'max_products' => 100,
            'max_employees' => 10,
        ],
        'pro' => [
            'price_monthly' => 75000,
            'max_products' => 500,
            'max_employees' => 50,
        ],
        'enterprise' => [
            'price_monthly' => 150000,
            'max_products' => 99999,
            'max_employees' => 99999,
        ],
    ];

    public const MONTHS_OPTIONS = [1, 3, 6, 12];

    public function planDefinitions(): array
    {
        $saved = Setting::where('key', 'subscription_plans')->first();
        $stored = $saved ? $saved->getTypedValue() : [];
        $stored = is_array($stored) ? $stored : [];

        $plans = [];
        foreach (self::DEFAULT_PLANS as $key => $defaults) {
            $plans[$key] = array_replace($defaults, $stored[$key] ?? []);
        }

        return $plans;
    }

    public function plansIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->ownerProfile;

        return response()->json([
            'current' => $profile ? [
                'subscription_status' => $profile->subscription_status,
                'subscription_plan' => $profile->subscription_plan,
                'subscription_expires_at' => $profile->subscription_expires_at,
                'max_products' => $profile->max_products,
                'max_employees' => $profile->max_employees,
            ] : null,
            'plans' => $this->planDefinitions(),
            'months_options' => self::MONTHS_OPTIONS,
        ]);
    }

    public function pay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => 'required|in:starter,pro,enterprise',
            'months' => 'required|in:1,3,6,12',
            'provider' => 'required|in:airtel,mixx_by_yas,mpesa,halopesa,clickpesa,cash',
            'phone_number' => 'nullable|string|max:20',
        ]);

        $plans = $this->planDefinitions();
        $plan = $plans[$validated['plan']];
        $amount = (float) $plan['price_monthly'] * (int) $validated['months'];

        $payment = SubscriptionPayment::create([
            'user_id' => $request->user()->id,
            'plan' => $validated['plan'],
            'months' => (int) $validated['months'],
            'amount' => $amount,
            'provider' => $validated['provider'],
            'phone_number' => $validated['phone_number'] ?? null,
            'status' => 'pending',
        ]);

        $isImmediate = in_array($validated['provider'], ['clickpesa', 'cash'], true);

        if ($isImmediate) {
            $this->markCompleted($payment);
        }

        return response()->json([
            'payment' => $payment,
            'activated' => $isImmediate,
            'message' => $isImmediate
                ? 'Payment confirmed. Your subscription is now active.'
                : 'Payment recorded. Your subscription will be activated once the payment is confirmed.',
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $payments = SubscriptionPayment::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($payments);
    }

    public function markCompleted(SubscriptionPayment $payment): void
    {
        $payment->update(['status' => 'completed']);
        $this->activate($payment);
    }

    public function activate(SubscriptionPayment $payment): void
    {
        $profile = OwnerProfile::where('user_id', $payment->user_id)->first();
        if (!$profile) {
            $profile = OwnerProfile::create([
                'user_id' => $payment->user_id,
                'is_active' => true,
                'subscription_status' => 'trial',
                'subscription_expires_at' => now()->addDays(30),
                'subscription_plan' => 'free',
                'max_products' => self::DEFAULT_PLANS['free']['max_products'],
                'max_employees' => self::DEFAULT_PLANS['free']['max_employees'],
            ]);
        }

        $plans = $this->planDefinitions();
        $plan = $plans[$payment->plan] ?? [];

        $now = now();
        $currentExpiry = $profile->subscription_expires_at;
        $base = in_array($profile->subscription_status, ['active', 'trial'], true)
            && $currentExpiry
            && $currentExpiry->isFuture()
            ? $currentExpiry
            : $now;

        $profile->update([
            'subscription_status' => 'active',
            'subscription_plan' => $payment->plan,
            'subscription_expires_at' => $base->copy()->addMonths((int) $payment->months),
            'max_products' => $plan['max_products'] ?? $profile->max_products,
            'max_employees' => $plan['max_employees'] ?? $profile->max_employees,
        ]);
    }
}
