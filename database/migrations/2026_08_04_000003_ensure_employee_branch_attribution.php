<?php

use App\Models\Branch;
use App\Models\Business;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure every business has a default branch so employees can be attributed.
        foreach (Business::all() as $business) {
            if (Branch::where('owner_id', $business->owner_id)->exists()) {
                continue;
            }

            Branch::create([
                'owner_id' => $business->owner_id,
                'name' => $business->name ?: 'Main Branch',
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        // Attribute legacy employees (no profile or no branch) to the first business,
        // so pre-multitenancy seed data remains visible to the original store owner.
        $fallbackBranchId = Branch::orderBy('id')->value('id');
        if (!$fallbackBranchId) {
            return;
        }

        $employees = User::where('role', 'employee')->get();
        foreach ($employees as $employee) {
            $profile = EmployeeProfile::where('user_id', $employee->id)->first();

            if ($profile && $profile->branch_id !== null) {
                continue;
            }

            if ($profile) {
                $profile->update(['branch_id' => $fallbackBranchId]);
            } else {
                EmployeeProfile::create([
                    'user_id' => $employee->id,
                    'branch_id' => $fallbackBranchId,
                    'employee_code' => 'EMP-' . strtoupper(\Illuminate\Support\Str::random(6)),
                    'position' => 'Staff',
                    'hire_date' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: nothing to undo.
    }
};
