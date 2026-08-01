<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    public const BUSINESS_TYPES = ['sole_proprietorship', 'partnership', 'limited_company', 'other'];

    public const DOCUMENT_CATEGORIES = [
        'contract', 'business_registration', 'tin_certificate', 'vat_certificate',
        'business_license', 'certificate_of_incorporation', 'identification', 'other',
    ];

    private function supplierForOwner(Request $request, string $id): Supplier
    {
        return Supplier::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->firstOrFail();
    }
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Supplier::where('owner_id', $user->id)->withCount('purchaseOrders');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('name')->paginate(20);
        return response()->json($suppliers);
    }

    public function all(Request $request): JsonResponse
    {
        $suppliers = Supplier::where('owner_id', $request->ownerId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'products_description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'business_type' => 'nullable|in:' . implode(',', self::BUSINESS_TYPES),
            'tin_number' => 'nullable|string|max:20',
            'vat_number' => 'nullable|string|max:20',
            'business_registration_number' => 'nullable|string|max:50',
        ]);

        $supplier = Supplier::create([
            ...$validated,
            'owner_id' => $request->ownerId(),
        ]);

        $storedPaths = [];
        try {
            $this->storeDocuments($supplier, $request, $storedPaths);
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::delete($path);
            }
            $supplier->delete();
            throw $e;
        }

        return response()->json($supplier->load('documents'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->withCount('purchaseOrders')
            ->with('purchaseOrders', 'documents')
            ->firstOrFail();

        return response()->json($supplier);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'products_description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
            'business_type' => 'nullable|in:' . implode(',', self::BUSINESS_TYPES),
            'tin_number' => 'nullable|string|max:20',
            'vat_number' => 'nullable|string|max:20',
            'business_registration_number' => 'nullable|string|max:50',
        ]);

        $supplier->update($validated);
        return response()->json($supplier);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->firstOrFail();

        if ($supplier->purchaseOrders()->count() > 0) {
            return response()->json(['message' => 'Supplier has purchase orders and cannot be deleted'], 422);
        }

        $supplier->delete();
        return response()->json(['message' => 'Supplier deleted']);
    }

    public function supplierProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $supplier = Supplier::where('owner_id', $user->owner_id ?? $user->id)
            ->where('email', $user->email)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier profile not found'], 404);
        }

        return response()->json($supplier);
    }

    private function storeDocuments(Supplier $supplier, Request $request, array &$storedPaths): void
    {
        if (!$request->hasFile('attachments')) {
            return;
        }

        $types = $request->input('document_types', []);

        foreach ($request->file('attachments') as $index => $file) {
            $path = $file->store('supplier-documents');
            $storedPaths[] = $path;

            SupplierDocument::create([
                'supplier_id' => $supplier->id,
                'category' => $types[$index] ?? 'other',
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    public function indexDocuments(Request $request, string $id): JsonResponse
    {
        $supplier = $this->supplierForOwner($request, $id);

        $documents = $supplier->documents()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'category', 'original_name', 'mime_type', 'size', 'created_at']);

        return response()->json(['data' => $documents]);
    }

    public function storeDocumentsForSupplier(Request $request, string $id): JsonResponse
    {
        $supplier = $this->supplierForOwner($request, $id);

        $validated = $request->validate([
            'attachments' => 'required|array|min:1',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
            'document_types' => 'nullable|array',
            'document_types.*' => 'in:' . implode(',', self::DOCUMENT_CATEGORIES),
        ], [
            'attachments.required' => 'Choose at least one file.',
            'attachments.*.mimes' => 'Documents must be PDF, JPG, PNG, DOC or DOCX.',
            'attachments.*.max' => 'Each document must be 20MB or smaller.',
        ]);

        $storedPaths = [];

        try {
            $this->storeDocuments($supplier, $request, $storedPaths);
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::delete($path);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Documents uploaded.',
            'documents_count' => $supplier->documents()->count(),
        ], 201);
    }

    public function destroyDocument(Request $request, string $id, SupplierDocument $document): JsonResponse
    {
        $supplier = $this->supplierForOwner($request, $id);

        if ($document->supplier_id !== $supplier->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        Storage::delete($document->file_path);
        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    public function downloadDocument(Request $request, string $id, SupplierDocument $document): StreamedResponse|JsonResponse
    {
        $supplier = $this->supplierForOwner($request, $id);

        if ($document->supplier_id !== $supplier->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if (!Storage::exists($document->file_path)) {
            return response()->json(['message' => 'File is no longer available.'], 404);
        }

        return Storage::download($document->file_path, $document->original_name);
    }
}
