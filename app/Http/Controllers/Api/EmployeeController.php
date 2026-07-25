<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $employees = User::where('role', 'employee')
            ->with('employeeProfile.branch:id,name,city')
            ->withCount('documents')
            ->select('id', 'name', 'email', 'phone', 'is_active', 'created_at', 'password_changed_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($emp) => array_merge($emp->toArray(), [
                'status' => $emp->is_active ? 'active' : 'inactive',
                'license_number' => $emp->employeeProfile?->employee_code ?? null,
                'vehicle_name' => null,
            ]));

        return response()->json(['data' => $employees]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|exists:branches,id',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
        ], [
            'name.required' => 'Please enter the employee\'s full name.',
            'name.string' => 'Name must be a valid text.',
            'name.max' => 'Name is too long. Please use 255 characters or fewer.',
            'email.required' => 'Please enter the employee\'s email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'An account with this email already exists.',
            'phone.max' => 'Phone number is too long. Please check and try again.',
        ]);

        $defaultPassword = strtoupper($validated['name']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($defaultPassword),
            'role' => 'employee',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        EmployeeProfile::create([
            'user_id' => $user->id,
            'branch_id' => $validated['branch_id'] ?? null,
            'employee_code' => 'EMP-' . strtoupper(\Illuminate\Support\Str::random(6)),
            'position' => 'Staff',
            'department' => 'General',
            'hire_date' => now(),
            'commission_rate' => $validated['commission_rate'] ?? 0,
        ]);

        $user->load('employeeProfile.branch:id,name,city');

        return response()->json([
            'user' => $user->only('id', 'name', 'email', 'role'),
            'default_password' => $defaultPassword,
            'message' => "Employee created. Default password: {$defaultPassword}",
        ], 201);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'user' => $user->only('id', 'name', 'email', 'is_active'),
            'message' => $user->is_active ? 'Employee activated' : 'Employee deactivated',
        ]);
    }

    public function assignBranch(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $profile = $user->employeeProfile;
        if (!$profile) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $profile->update(['branch_id' => $validated['branch_id'] ?? null]);

        $user->load('employeeProfile.branch:id,name,city');

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'message' => $validated['branch_id'] ? 'Branch assigned.' : 'Branch removed.',
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->isOwner()) {
            return response()->json(['message' => 'Cannot delete an owner'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Employee deleted']);
    }

    public function updateProfile(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'position' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
        ]);

        $profile = $user->employeeProfile;
        if (!$profile) {
            return response()->json(['message' => 'Employee profile not found'], 404);
        }

        $profile->update($validated);
        $user->load('employeeProfile.branch:id,name,city');

        return response()->json([
            'user' => $user,
            'message' => 'Employee profile updated',
        ]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        if ($user->role !== 'employee') {
            return response()->json(['message' => 'Can only reset employee passwords'], 422);
        }

        $defaultPassword = strtoupper($user->name);

        $user->update([
            'password' => Hash::make($defaultPassword),
            'password_changed_at' => null,
        ]);

        return response()->json([
            'message' => 'Employee password reset to default',
            'default_password' => $defaultPassword,
        ]);
    }
}
