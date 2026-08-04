<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CustomerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|max:20',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ], [
            'name.required' => 'Please enter your full name.',
            'name.string' => 'Name must be a valid text.',
            'name.max' => 'Name is too long. Please use 255 characters or fewer.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address (e.g. john@example.com).',
            'email.unique' => 'An account with this email already exists. Please use a different email.',
            'phone.required' => 'Please enter your phone number.',
            'phone.max' => 'Phone number is too long. Please check and try again.',
            'password.required' => 'Please create a password for your account.',
            'password.confirmed' => 'The password confirmation does not match. Please re-type your password.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.mixedCase' => 'Password must contain at least one uppercase letter (A-Z) and one lowercase letter (a-z).',
            'password.numbers' => 'Password must contain at least one number (0-9).',
            'password.symbols' => 'Password must contain at least one special character (e.g. !@#$%^&*).',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = 'customer';
        $validated['password_changed_at'] = now();

        $user = User::create($validated);
        $user->customerProfile()->create([]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'password.required' => 'Please enter your password.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->isLocked()) {
            $remainingMinutes = now()->diffInMinutes($user->locked_until);
            return response()->json([
                'message' => "Account is locked. Try again in {$remainingMinutes} minutes.",
                'locked_until' => $user->locked_until->toIso8601String(),
            ], 423);
        }

        if (!Hash::check($request->password, $user->password)) {
            $user->recordFailedLogin();

            $remainingAttempts = User::MAX_LOGIN_ATTEMPTS - $user->failed_login_attempts;
            if ($remainingAttempts <= 0) {
                return response()->json([
                    'message' => 'Account locked due to too many failed attempts. Try again in 30 minutes.',
                    'locked_until' => $user->locked_until->toIso8601String(),
                ], 423);
            }

            return response()->json([
                'message' => 'Invalid credentials',
                'remaining_attempts' => $remainingAttempts,
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        $user->resetFailedLoginAttempts();

        $token = $user->createToken('auth-token')->plainTextToken;

        $loadedUser = $user;
        if ($user->isOwner()) {
            $loadedUser = $user->load('ownerProfile', 'addresses');
        } elseif ($user->isSuperadmin()) {
            $loadedUser = $user->load('ownerProfile');
        } else {
            $loadedUser = $user->load('customerProfile', 'addresses');
        }

        return response()->json([
            'user' => $loadedUser,
            'token' => $token,
            'must_change_password' => $user->mustChangePassword(),
            'superadmin_password_expired' => $user->isSuperadmin() ? $user->superadminPasswordExpired() : false,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isOwner()) {
            $user = $user->load('ownerProfile', 'addresses');
        } elseif ($user->isSuperadmin()) {
            $user = $user->load('ownerProfile');
        } else {
            $user = $user->load('customerProfile', 'addresses');
        }

        return response()->json(array_merge($user->toArray(), [
            'must_change_password' => $user->mustChangePassword(),
            'superadmin_password_expired' => $user->isSuperadmin() ? $user->superadminPasswordExpired() : false,
        ]));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isOwner()) {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'nullable|string|max:20',
                'brand_store_name' => 'nullable|string|max:255',
                'brand_tagline' => 'nullable|string|max:255',
                'brand_color' => 'nullable|string|max:7',
                'brand_color_secondary' => 'nullable|string|max:7',
            ]);

            $user->update($request->only(['name', 'email', 'phone']));

            if ($user->ownerProfile) {
                $profileData = array_filter($request->only([
                    'brand_store_name', 'brand_tagline', 'brand_color', 'brand_color_secondary',
                ]), fn($v) => $v !== null);
                if (!empty($profileData)) {
                    $user->ownerProfile()->update($profileData);
                }
            }

            return response()->json($user->fresh()->load('ownerProfile'));
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
        ]);

        $user->update($request->only(['name', 'email', 'phone']));

        if (isset($validated['date_of_birth'])) {
            $user->customerProfile()->updateOrCreate(
                ['user_id' => $user->id],
                ['date_of_birth' => $validated['date_of_birth']]
            );
        }

        return response()->json($user->fresh()->load('customerProfile'));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ], [
            'current_password.required' => 'Please enter your current password.',
            'password.required' => 'Please create a new password.',
            'password.confirmed' => 'The new password confirmation does not match. Please re-type your password.',
            'password.min' => 'New password must be at least 8 characters long.',
            'password.mixedCase' => 'New password must contain at least one uppercase letter (A-Z) and one lowercase letter (a-z).',
            'password.numbers' => 'New password must contain at least one number (0-9).',
            'password.symbols' => 'New password must contain at least one special character (e.g. !@#$%^&*).',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'password_changed_at' => now(),
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}
