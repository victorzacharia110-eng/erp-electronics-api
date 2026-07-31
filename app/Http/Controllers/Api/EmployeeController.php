<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\EmployeeGuarantor;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $employees = User::where('role', 'employee')
            ->with('employeeProfile.branch:id,name,city')
            ->withCount(['documents', 'guarantors'])
            ->select('id', 'name', 'email', 'phone', 'is_active', 'created_at', 'password_changed_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($emp) => array_merge($emp->toArray(), [
                'status' => $emp->is_active ? 'active' : 'inactive',
                'license_number' => $emp->employeeProfile?->employee_code ?? null,
                'nida_number' => $emp->employeeProfile?->nida_number ?? null,
                'voting_id_number' => $emp->employeeProfile?->voting_id_number ?? null,
                'vehicle_name' => null,
            ]));

        return response()->json(['data' => $employees]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|max:20',
            'nida_number' => 'nullable|string|max:20|required_without:voting_id_number',
            'voting_id_number' => 'nullable|string|max:30|required_without:nida_number',
            'branch_id' => 'nullable|exists:branches,id',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'guarantors' => 'required|array|min:1',
            'guarantors.*.full_name' => 'required|string|max:255',
            'guarantors.*.phone' => 'required|string|max:20',
            'guarantors.*.relationship' => 'required|string|max:100',
            'guarantors.*.address' => 'nullable|string|max:255',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
            'document_types' => 'nullable|array',
            'document_types.*' => 'in:contract,background_check,other',
        ], [
            'name.required' => 'Please enter the employee\'s full name.',
            'name.string' => 'Name must be a valid text.',
            'name.max' => 'Name is too long. Please use 255 characters or fewer.',
            'email.required' => 'Please enter the employee\'s email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'An account with this email already exists.',
            'phone.required' => 'Please enter the employee\'s phone number.',
            'phone.max' => 'Phone number is too long. Please check and try again.',
            'nida_number.required_without' => 'Provide the NIDA number or the Voting ID card number.',
            'voting_id_number.required_without' => 'Provide the NIDA number or the Voting ID card number.',
            'guarantors.required' => 'At least one Wadhamini (guarantor) is required.',
            'guarantors.min' => 'At least one Wadhamini (guarantor) is required.',
            'guarantors.*.full_name.required' => 'Guarantor full name is required.',
            'guarantors.*.phone.required' => 'Guarantor phone number is required.',
            'guarantors.*.relationship.required' => 'Guarantor relationship is required.',
            'attachments.*.mimes' => 'Attachments must be PDF, JPG, PNG, DOC or DOCX.',
            'attachments.*.max' => 'Each attachment must be 20MB or smaller.',
        ]);

        $defaultPassword = strtoupper($validated['name']);
        $storedPaths = [];

        try {
            $user = DB::transaction(function () use ($validated, $defaultPassword, $request, &$storedPaths) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
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
                    'hire_date' => now(),
                    'commission_rate' => $validated['commission_rate'] ?? 0,
                    'nida_number' => $validated['nida_number'] ?? null,
                    'voting_id_number' => $validated['voting_id_number'] ?? null,
                ]);

                foreach ($validated['guarantors'] as $guarantor) {
                    EmployeeGuarantor::create(array_merge($guarantor, [
                        'user_id' => $user->id,
                    ]));
                }

                $this->storeAttachments($user, $request, $storedPaths);

                return $user;
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::delete($path);
            }

            throw $e;
        }

        $user->load(['employeeProfile.branch:id,name,city', 'documents', 'guarantors']);

        return response()->json([
            'user' => $user->only('id', 'name', 'email', 'role'),
            'documents_count' => $user->documents->count(),
            'guarantors_count' => $user->guarantors->count(),
            'default_password' => $defaultPassword,
            'message' => "Employee created. Default password: {$defaultPassword}",
        ], 201);
    }

    private function storeAttachments(User $user, Request $request, array &$storedPaths): void
    {
        if (!$request->hasFile('attachments')) {
            return;
        }

        $types = $request->input('document_types', []);

        foreach ($request->file('attachments') as $index => $file) {
            $path = $file->store('employee-documents');
            $storedPaths[] = $path;

            EmployeeDocument::create([
                'user_id' => $user->id,
                'category' => $types[$index] ?? 'other',
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    public function indexDocuments(User $user): JsonResponse
    {
        $documents = $user->documents()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'category', 'original_name', 'mime_type', 'size', 'created_at']);

        return response()->json(['data' => $documents]);
    }

    public function storeDocuments(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'attachments' => 'required|array|min:1',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
            'document_types' => 'nullable|array',
            'document_types.*' => 'in:contract,background_check,other',
        ], [
            'attachments.required' => 'Choose at least one file.',
            'attachments.*.mimes' => 'Attachments must be PDF, JPG, PNG, DOC or DOCX.',
            'attachments.*.max' => 'Each attachment must be 20MB or smaller.',
        ]);

        $storedPaths = [];

        try {
            $this->storeAttachments($user, $request, $storedPaths);
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::delete($path);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Attachments uploaded.',
            'documents_count' => $user->documents()->count(),
        ], 201);
    }

    public function destroyDocument(User $user, EmployeeDocument $document): JsonResponse
    {
        if ($document->user_id !== $user->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        Storage::delete($document->file_path);
        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    public function downloadDocument(User $user, EmployeeDocument $document): StreamedResponse|JsonResponse
    {
        if ($document->user_id !== $user->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if (!Storage::exists($document->file_path)) {
            return response()->json(['message' => 'File is no longer available.'], 404);
        }

        return Storage::download($document->file_path, $document->original_name);
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
