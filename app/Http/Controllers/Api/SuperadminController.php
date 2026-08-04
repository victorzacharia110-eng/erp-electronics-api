<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\OwnerProfile;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SuperadminController extends Controller
{
    public function stats()
    {
        $totalOwners = User::where('role', 'owner')->count();
        $activeOwners = OwnerProfile::where('is_active', true)->count();
        $totalEmployees = User::where('role', 'employee')->count();
        $totalCustomers = User::where('role', 'customer')->count();

        $subscriptions = OwnerProfile::select('subscription_status')
            ->selectRaw('count(*) as count')
            ->groupBy('subscription_status')
            ->pluck('count', 'subscription_status');

        $totalRevenue = User::where('role', 'owner')
            ->with('orders')
            ->get()
            ->flatMap->orders
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        return response()->json([
            'total_owners' => $totalOwners,
            'active_owners' => $activeOwners,
            'total_employees' => $totalEmployees,
            'total_customers' => $totalCustomers,
            'subscriptions' => $subscriptions,
            'total_revenue' => $totalRevenue,
        ]);
    }

    public function index()
    {
        $owners = User::where('role', 'owner')
            ->with('ownerProfile')
            ->get();

        return response()->json($owners);
    }

    public function show($id)
    {
        $owner = User::where('role', 'owner')
            ->with(['ownerProfile', 'orders' => fn($q) => $q->latest()->limit(10)])
            ->findOrFail($id);

        $orders = Order::whereHas('branch', function ($q) use ($owner) {
            $q->where('owner_id', $owner->id);
        });

        $stats = [
            'total_orders' => (clone $orders)->count(),
            'total_revenue' => (clone $orders)->where('status', '!=', 'cancelled')->sum('total'),
            'product_count' => Product::where('owner_id', $owner->id)->count(),
            'employee_count' => User::where('role', 'employee')
                ->whereHas('employeeProfile.branch', function ($q) use ($owner) {
                    $q->where('owner_id', $owner->id);
                })
                ->count(),
        ];

        return response()->json(['owner' => $owner, 'stats' => $stats]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20',
            'max_products' => 'nullable|integer|min:1',
            'max_employees' => 'nullable|integer|min:1',
            'subscription_plan' => 'nullable|string|in:free,starter,pro,enterprise',
        ]);

        $defaultPassword = strtoupper($validated['name']) . '@' . rand(100, 999);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($defaultPassword),
            'role' => 'owner',
            'is_active' => true,
        ]);

        OwnerProfile::create([
            'user_id' => $user->id,
            'is_active' => true,
            'subscription_status' => 'trial',
            'subscription_expires_at' => now()->addDays(30),
            'subscription_plan' => $validated['subscription_plan'] ?? 'starter',
            'max_products' => $validated['max_products'] ?? 50,
            'max_employees' => $validated['max_employees'] ?? 5,
        ]);

        $this->createBusinessForOwner($user, $validated['name']);

        return response()->json([
            'owner' => $user->load('ownerProfile'),
            'default_password' => $defaultPassword,
        ], 201);
    }

    private function createBusinessForOwner(User $owner, string $displayName): void
    {
        $profile = $owner->ownerProfile;
        $baseName = $profile?->brand_store_name ?: $displayName;
        $slug = Str::slug($baseName) ?: 'store-' . $owner->id;

        if (Business::where('slug', $slug)->exists()) {
            $slug = Str::slug($baseName) . '-' . $owner->id;
        }

        Business::create([
            'owner_id' => $owner->id,
            'name' => $baseName,
            'slug' => $slug,
            'tagline' => $profile?->brand_tagline,
            'logo_path' => $profile?->brand_logo_path,
            'is_active' => true,
        ]);
    }

    public function toggleActive($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = $owner->ownerProfile()->firstOrCreate(['user_id' => $owner->id]);
        $profile->is_active = !$profile->is_active;
        $profile->save();

        $owner->is_active = $profile->is_active;
        $owner->save();

        return response()->json([
            'message' => $profile->is_active ? 'Owner activated' : 'Owner deactivated',
            'owner' => $owner->fresh('ownerProfile'),
        ]);
    }

    public function updateSubscription(Request $request, $id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = $owner->ownerProfile()->firstOrCreate(['user_id' => $owner->id]);

        $validated = $request->validate([
            'subscription_status' => 'required|string|in:trial,active,suspended,expired',
            'subscription_expires_at' => 'nullable|date',
            'subscription_plan' => 'nullable|string|in:free,starter,pro,enterprise',
        ]);

        $profile->update($validated);

        // Reactivating with a future expiry (manual extension) clears the
        // automatic deactivation so the dashboard no longer prompts.
        if (in_array($validated['subscription_status'], ['trial', 'active'], true)) {
            $expiry = $validated['subscription_expires_at'] ?? $profile->subscription_expires_at;
            if ($expiry && now()->lt($expiry)) {
                $profile->update([
                    'is_active' => true,
                    'deactivation_reason' => null,
                ]);
                $owner->update(['is_active' => true]);
            }
        }

        return response()->json([
            'message' => 'Subscription updated',
            'owner' => $owner->fresh('ownerProfile'),
        ]);
    }

    public function extendTrial(Request $request, $id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = $owner->ownerProfile()->firstOrCreate(['user_id' => $owner->id]);

        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        $days = $validated['days'] ?? 30;

        $profile->update([
            'subscription_status' => 'trial',
            'subscription_expires_at' => now()->addDays($days),
            'is_active' => true,
            'deactivation_reason' => null,
        ]);

        $owner->update(['is_active' => true]);

        return response()->json([
            'message' => "Trial extended by {$days} day(s)",
            'owner' => $owner->fresh('ownerProfile'),
        ]);
    }

    public function updateLimits(Request $request, $id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = $owner->ownerProfile()->firstOrCreate(['user_id' => $owner->id]);

        $validated = $request->validate([
            'max_products' => 'required|integer|min:1',
            'max_employees' => 'required|integer|min:1',
        ]);

        $profile->update($validated);

        return response()->json([
            'message' => 'Limits updated',
            'owner' => $owner->fresh('ownerProfile'),
        ]);
    }

    public function updateBranding(Request $request, $id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = $owner->ownerProfile()->firstOrCreate(['user_id' => $owner->id]);

        $validated = $request->validate([
            'brand_store_name' => 'nullable|string|max:255',
            'brand_tagline' => 'nullable|string|max:255',
            'brand_color' => 'nullable|string|max:7',
            'brand_color_secondary' => 'nullable|string|max:7',
        ]);

        $profile->update($validated);

        // Keep the public store record (directory/storefront) in sync with branding changes
        $business = Business::where('owner_id', $owner->id)->first();
        if ($business) {
            $data = [];
            if (array_key_exists('brand_store_name', $validated) && $validated['brand_store_name']) {
                $data['name'] = $validated['brand_store_name'];
            }
            if (array_key_exists('brand_tagline', $validated)) {
                $data['tagline'] = $validated['brand_tagline'] ?? '';
            }
            if ($data) {
                $business->update($data);
            }
        }

        return response()->json([
            'message' => 'Branding updated',
            'owner' => $owner->fresh('ownerProfile'),
        ]);
    }

    public function updateBrandingLogo(Request $request, $id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = $owner->ownerProfile()->firstOrCreate(['user_id' => $owner->id]);

        $request->validate([
            'logo' => 'required|image|max:2048',
        ]);

        $file = $request->file('logo');
        $filename = 'brand_' . $owner->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('branding'), $filename);

        // Delete old logo
        if ($profile->brand_logo_path && file_exists(public_path('branding/' . $profile->brand_logo_path))) {
            unlink(public_path('branding/' . $profile->brand_logo_path));
        }

        $profile->update(['brand_logo_path' => $filename]);

        // Keep the public store record (directory/storefront) in sync with the uploaded logo
        Business::where('owner_id', $owner->id)->update(['logo_path' => $filename]);

        return response()->json([
            'message' => 'Logo updated',
            'logo_url' => '/branding/' . $filename,
            'owner' => $owner->fresh('ownerProfile'),
        ]);
    }

    public function destroy($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        \DB::table('orders')->where('handled_by', $owner->id)->update(['handled_by' => null]);
        \DB::table('orders')->where('user_id', $owner->id)->delete();
        \DB::table('subscription_payments')->where('user_id', $owner->id)->delete();
        Business::where('owner_id', $owner->id)->delete();
        $owner->ownerProfile()->delete();
        $owner->delete();

        return response()->json(['message' => 'Owner deleted']);
    }

    public function resetPassword($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);
        $defaultPassword = strtoupper($owner->name) . '@' . rand(100, 999);

        $owner->update([
            'password' => Hash::make($defaultPassword),
            'password_changed_at' => null,
        ]);

        $owner->unlockAccount();

        return response()->json([
            'message' => 'Owner password reset to default',
            'default_password' => $defaultPassword,
            'must_change_password' => true,
        ]);
    }

    public function getPasswordStatus($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);

        return response()->json([
            'owner_id' => $owner->id,
            'owner_name' => $owner->name,
            'owner_email' => $owner->email,
            'password_status' => $owner->getPasswordStatus(),
        ]);
    }

    public function setPassword(Request $request, $id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);

        $validated = $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'force_change_on_login' => 'nullable|boolean',
        ], [
            'password.required' => 'Please enter a new password.',
            'password.confirmed' => 'The password confirmation does not match.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.mixedCase' => 'Password must contain uppercase and lowercase letters.',
            'password.numbers' => 'Password must contain at least one number.',
            'password.symbols' => 'Password must contain at least one special character.',
        ]);

        $forceChange = $validated['force_change_on_login'] ?? true;

        $owner->update([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => $forceChange ? null : now(),
        ]);

        $owner->unlockAccount();

        return response()->json([
            'message' => 'Owner password updated successfully',
            'force_change_on_login' => $forceChange,
        ]);
    }

    public function forcePasswordChange($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);

        $owner->update(['password_changed_at' => null]);

        return response()->json([
            'message' => 'Owner will be forced to change password on next login',
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'must_change_password' => true,
            ],
        ]);
    }

    public function unlockAccount($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);

        if (!$owner->isLocked()) {
            return response()->json([
                'message' => 'Account is not locked',
                'owner' => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'is_account_locked' => false,
                ],
            ]);
        }

        $owner->unlockAccount();

        return response()->json([
            'message' => 'Owner account unlocked successfully',
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'is_account_locked' => false,
            ],
        ]);
    }

    public function subscriptionPlans()
    {
        return response()->json((new SubscriptionController)->planDefinitions());
    }

    public function updateSubscriptionPlans(Request $request)
    {
        $validated = $request->validate([
            'starter' => 'required|array',
            'pro' => 'required|array',
            'enterprise' => 'required|array',
        ]);

        $allowed = ['price_monthly', 'max_products', 'max_employees'];
        $clean = [];
        foreach ($validated as $key => $plan) {
            $clean[$key] = array_intersect_key($plan, array_flip($allowed));
        }

        Setting::updateOrCreate(
            ['key' => 'subscription_plans'],
            [
                'value' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
            ]
        );

        return response()->json([
            'message' => 'Subscription plans updated',
            'plans' => (new SubscriptionController)->planDefinitions(),
        ]);
    }

    public function subscriptionPayments()
    {
        $payments = SubscriptionPayment::with('user:id,name,email')
            ->latest()
            ->get();

        return response()->json($payments);
    }

    public function ownerSubscriptionPayments($id)
    {
        User::where('role', 'owner')->findOrFail($id);

        $payments = SubscriptionPayment::where('user_id', $id)
            ->with('user:id,name,email')
            ->latest()
            ->get();

        return response()->json($payments);
    }

    public function confirmSubscriptionPayment($id)
    {
        $payment = SubscriptionPayment::findOrFail($id);

        if ($payment->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending payments can be confirmed',
                'payment' => $payment->fresh(),
            ], 422);
        }

        (new SubscriptionController)->markCompleted($payment);

        return response()->json([
            'message' => 'Payment confirmed and subscription activated',
            'payment' => $payment->fresh(),
        ]);
    }

    public function allPasswordsStatus()
    {
        $owners = User::where('role', 'owner')
            ->select('id', 'name', 'email', 'password_changed_at', 'failed_login_attempts', 'locked_until')
            ->get()
            ->map(fn($owner) => array_merge($owner->toArray(), [
                'password_status' => $owner->getPasswordStatus(),
            ]));

        $mustChangeCount = $owners->filter(fn($o) => $o['password_status']['must_change_password'])->count();
        $lockedCount = $owners->filter(fn($o) => $o['password_status']['is_account_locked'])->count();

        return response()->json([
            'owners' => $owners,
            'summary' => [
                'total' => $owners->count(),
                'must_change_password' => $mustChangeCount,
                'locked_accounts' => $lockedCount,
            ],
        ]);
    }
}
