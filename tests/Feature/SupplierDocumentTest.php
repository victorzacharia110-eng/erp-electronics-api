<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_list_and_delete_supplier_documents(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create(['role' => 'owner']);
        $supplier = Supplier::create([
            'owner_id' => $owner->id,
            'name' => 'Dar Electronics Ltd',
            'email' => 'supplier@example.com',
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $upload = $this->withToken($token)->postJson('/api/suppliers/' . $supplier->id . '/documents', [
            'attachments' => [
                UploadedFile::fake()->create('tin_certificate.pdf', 100, 'application/pdf'),
            ],
            'document_types' => ['tin_certificate'],
        ]);

        $upload->assertStatus(201)->assertJson(['documents_count' => 1]);

        $this->assertDatabaseHas('supplier_documents', [
            'supplier_id' => $supplier->id,
            'category' => 'tin_certificate',
            'original_name' => 'tin_certificate.pdf',
        ]);

        $list = $this->withToken($token)->getJson('/api/suppliers/' . $supplier->id . '/documents');
        $list->assertOk();
        $documentId = $list->json('data.0.id');

        $this->withToken($token)->getJson('/api/suppliers/' . $supplier->id . '/documents/' . $documentId . '/download')
            ->assertOk();

        $this->withToken($token)->deleteJson('/api/suppliers/' . $supplier->id . '/documents/' . $documentId)
            ->assertOk();

        $this->assertDatabaseCount('supplier_documents', 0);
    }

    public function test_owner_can_create_supplier_with_documents_and_legal_fields(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create(['role' => 'owner']);
        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/suppliers', [
            'name' => 'Tech Supplies Ltd',
            'email' => 'sales@techsupplies.co.tz',
            'business_type' => 'limited_company',
            'tin_number' => '123456789',
            'vat_number' => 'VAT-0000123456',
            'business_registration_number' => '123456789',
            'attachments' => [
                UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('brela.pdf', 100, 'application/pdf'),
            ],
            'document_types' => ['contract', 'business_registration'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'documents');
        $this->assertDatabaseHas('suppliers', [
            'name' => 'Tech Supplies Ltd',
            'business_type' => 'limited_company',
            'tin_number' => '123456789',
        ]);
        $this->assertDatabaseCount('supplier_documents', 2);
    }

    public function test_other_owner_cannot_access_supplier_documents(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $other = User::factory()->create(['role' => 'owner']);
        $supplier = Supplier::create(['owner_id' => $owner->id, 'name' => 'Private Supplier']);

        $token = $other->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/suppliers/' . $supplier->id . '/documents')->assertNotFound();
        $this->withToken($token)->postJson('/api/suppliers/' . $supplier->id . '/documents', [
            'attachments' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
            'document_types' => ['contract'],
        ])->assertNotFound();
    }
}
