<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
