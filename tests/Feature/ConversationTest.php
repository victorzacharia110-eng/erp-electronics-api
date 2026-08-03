<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Conversation;
use App\Models\EmployeeProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_list_owners_as_contacts(): void
    {
        User::factory()->create(['role' => 'owner', 'name' => 'Alice Store']);
        User::factory()->create(['role' => 'owner', 'name' => 'Bob Mart']);

        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $token = $superadmin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/conversations/contacts');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertEquals('owner', $response->json('data.0.role'));
    }

    public function test_owner_can_start_conversation_with_a_store_customer(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $customer = User::factory()->create(['role' => 'customer']);
        Order::create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $contacts = $this->withToken($token)->getJson('/api/conversations/contacts');
        $contacts->assertOk();
        $this->assertCount(1, $contacts->json('data'));
        $this->assertEquals($customer->id, $contacts->json('data.0.id'));

        $response = $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Order follow-up',
            'message' => 'Hi, how was your order?',
            'type' => 'customer_owner',
            'customer_id' => $customer->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('conversations', [
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'type' => 'customer_owner',
        ]);
    }

    public function test_owner_cannot_message_a_customer_who_never_ordered(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $stranger = User::factory()->create(['role' => 'customer']);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Spam',
            'message' => 'Hello',
            'type' => 'customer_owner',
            'customer_id' => $stranger->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_store_reuses_existing_open_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $customer = User::factory()->create(['role' => 'customer']);
        Order::create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'First',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Another subject',
            'message' => 'Second message',
            'type' => 'customer_owner',
            'customer_id' => $customer->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame($conversation->id, $response->json('id'));
    }

    public function test_employee_cannot_see_owner_conversations(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $conversation = Conversation::create([
            'type' => 'superadmin_owner',
            'owner_id' => $owner->id,
            'subject' => 'Private',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $employee = User::factory()->create(['role' => 'employee']);
        $token = $employee->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_owner_can_start_conversation_with_their_employee(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $employee = User::factory()->create(['role' => 'employee']);
        EmployeeProfile::create([
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
            'position' => 'Cashier',
            'employee_code' => 'EMP' . Str::random(4),
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $contacts = $this->withToken($token)->getJson('/api/conversations/contacts');
        $contacts->assertOk();
        $this->assertEquals('employee', $contacts->json('data.0.role'));
        $this->assertEquals($employee->id, $contacts->json('data.0.id'));

        $response = $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Shift notes',
            'message' => 'Please check today\'s closing.',
            'type' => 'owner_employee',
            'employee_id' => $employee->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('conversations', [
            'owner_id' => $owner->id,
            'employee_id' => $employee->id,
            'type' => 'owner_employee',
        ]);
    }

    public function test_owner_cannot_message_another_business_employee(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $otherOwner = User::factory()->create(['role' => 'owner']);
        $otherBranch = Branch::create(['owner_id' => $otherOwner->id, 'name' => 'Other Store']);
        $otherEmployee = User::factory()->create(['role' => 'employee']);
        EmployeeProfile::create([
            'user_id' => $otherEmployee->id,
            'branch_id' => $otherBranch->id,
            'position' => 'Manager',
            'employee_code' => 'EMP' . Str::random(4),
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Poach',
            'message' => 'Work for me?',
            'type' => 'owner_employee',
            'employee_id' => $otherEmployee->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_employee_can_start_conversation_with_their_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $employee = User::factory()->create(['role' => 'employee']);
        EmployeeProfile::create([
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
            'position' => 'Cashier',
            'employee_code' => 'EMP' . Str::random(4),
        ]);

        $token = $employee->createToken('test')->plainTextToken;

        $contacts = $this->withToken($token)->getJson('/api/conversations/contacts');
        $contacts->assertOk();
        $this->assertCount(1, $contacts->json('data'));
        $this->assertEquals('owner', $contacts->json('data.0.role'));
        $this->assertEquals($owner->id, $contacts->json('data.0.id'));

        $response = $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Need permission',
            'message' => 'Can I close the store early?',
            'type' => 'owner_employee',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('conversations', [
            'owner_id' => $owner->id,
            'employee_id' => $employee->id,
            'type' => 'owner_employee',
        ]);

        $this->withToken($token)->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_employee_without_branch_cannot_start_conversation(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $token = $employee->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/conversations', [
            'subject' => 'Hello',
            'message' => 'Anyone there?',
            'type' => 'owner_employee',
        ])->assertForbidden();

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_customer_can_delete_their_own_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $conversation->messages()->create(['sender_id' => $owner->id, 'message' => 'Hi there']);
        $conversation->messages()->create(['sender_id' => $customer->id, 'message' => 'Hi!']);

        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}")
            ->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('conversation_messages', 0);
    }

    public function test_customer_cannot_delete_another_customers_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $token = $otherCustomer->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_owner_can_delete_conversation_with_their_employee(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $employee = User::factory()->create(['role' => 'employee']);
        $conversation = Conversation::create([
            'type' => 'owner_employee',
            'owner_id' => $owner->id,
            'employee_id' => $employee->id,
            'subject' => 'Shift',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}")
            ->assertOk();

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_superadmin_can_only_delete_superadmin_owner_conversations(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $token = $superadmin->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_customer_can_delete_their_own_message(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $msg = $conversation->messages()->create(['sender_id' => $customer->id, 'message' => 'My message']);

        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}/messages/{$msg->id}")
            ->assertOk();

        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_customer_cannot_delete_another_users_message(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $msg = $conversation->messages()->create(['sender_id' => $owner->id, 'message' => 'Owners message']);

        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}/messages/{$msg->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_customer_cannot_delete_message_in_another_users_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $msg = $conversation->messages()->create(['sender_id' => $customer->id, 'message' => 'My message']);

        $token = $otherCustomer->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}/messages/{$msg->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_deleting_message_not_in_conversation_returns_404(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = User::factory()->create(['role' => 'customer']);
        $conversation = Conversation::create([
            'type' => 'customer_owner',
            'owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'subject' => 'Hello',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/conversations/{$conversation->id}/messages/999")
            ->assertNotFound();
    }
}
