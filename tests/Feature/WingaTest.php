<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Category;
use App\Models\EmployeeProfile;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Winga;
use App\Models\WingaCommission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WingaTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccounts(User $owner): void
    {
        $accounts = [
            ['code' => '1020', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '4010', 'name' => 'Sales', 'type' => 'revenue', 'normal_balance' => 'credit'],
            ['code' => '5010', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '2500', 'name' => 'VAT Output', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '5110', 'name' => 'Commission Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Winga Commission Payable', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2120', 'name' => 'Withholding Tax Payable (TDS)', 'type' => 'liability', 'normal_balance' => 'credit'],
        ];

        foreach ($accounts as $account) {
            Account::create(array_merge($account, ['owner_id' => $owner->id]));
        }
    }

    private function makeWingaOrder(User $owner, Branch $branch, Winga $winga): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics-' . Str::random(4)]);
        $product = Product::create([
            'owner_id' => $owner->id,
            'sku' => 'SKU-' . Str::random(6),
            'name' => 'Bluetooth Speaker',
            'category_id' => $category->id,
            'price' => 100,
            'cost_price' => 60,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-' . Str::random(6),
            'price' => 100,
            'cost_price' => 60,
        ]);
        Inventory::create([
            'product_variant_id' => $variant->id,
            'quantity_on_hand' => 10,
            'reorder_level' => 2,
        ]);

        $subtotal = 1000;
        $wingaFee = 100;

        $order = Order::create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'shipping_cost' => 0,
            'total' => $subtotal + $wingaFee,
            'winga_id' => $winga->id,
            'winga_fee' => $wingaFee,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity' => 10,
            'unit_price' => 100,
            'total' => 1000,
        ]);

        return $order->fresh(['items.productVariant.inventory', 'branch', 'winga']);
    }

    public function test_owner_can_create_and_list_wingas(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/wingas', [
            'name' => 'Juma Promoter',
            'phone' => '0712345678',
            'tin_number' => '123-456-789',
            'nida_number' => '19900101-12345-67890',
            'commission_rate' => 10,
            'branch_id' => $branch->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('wingas', [
            'owner_id' => $owner->id,
            'name' => 'Juma Promoter',
            'commission_rate' => '10.00',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)->getJson('/api/wingas');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Juma Promoter', $response->json('data.0.name'));
    }

    public function test_other_owner_cannot_modify_winga(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $intruder = User::factory()->create(['role' => 'owner']);
        $winga = Winga::create([
            'owner_id' => $owner->id,
            'name' => 'Mama Neema',
            'commission_rate' => 5,
        ]);
        $token = $intruder->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson("/api/wingas/{$winga->id}", [
            'name' => 'Hijacked',
            'commission_rate' => 5,
        ])->assertForbidden();

        $this->withToken($token)->deleteJson("/api/wingas/{$winga->id}")->assertForbidden();
        $this->assertDatabaseHas('wingas', ['id' => $winga->id]);
    }

    public function test_employee_can_view_business_wingas(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        Winga::create([
            'owner_id' => $owner->id,
            'branch_id' => $branch->id,
            'name' => 'Said',
            'commission_rate' => 10,
        ]);

        $employee = User::factory()->create(['role' => 'employee']);
        EmployeeProfile::create([
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
            'position' => 'Cashier',
            'employee_code' => 'EMP' . Str::random(4),
        ]);
        $token = $employee->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/wingas')->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_rejects_winga_of_another_business(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $otherOwner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $winga = Winga::create([
            'owner_id' => $otherOwner->id,
            'name' => 'Other Guy',
            'commission_rate' => 10,
        ]);

        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics-' . Str::random(4)]);
        $product = Product::create([
            'owner_id' => $owner->id,
            'sku' => 'SKU-' . Str::random(6),
            'name' => 'Item',
            'category_id' => $category->id,
            'price' => 100,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-' . Str::random(6),
            'price' => 100,
        ]);

        $cart = Order::create([
            'user_id' => $owner->id,
            'status' => 'pending_payment',
            'subtotal' => 1000,
            'total' => 1000,
        ]);
        OrderItem::create([
            'order_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 10,
            'unit_price' => 100,
            'total' => 1000,
        ]);

        $address = Address::create([
            'user_id' => $owner->id,
            'label' => 'Home',
            'street' => 'Mikocheni',
            'city' => 'Dar es Salaam',
        ]);

        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/orders', [
            'shipping_address_id' => $address->id,
            'branch_id' => $branch->id,
            'winga_id' => $winga->id,
        ])->assertStatus(422);
    }

    public function test_confirmed_winga_order_creates_pending_commission_with_tra_wht(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->seedAccounts($owner);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $winga = Winga::create([
            'owner_id' => $owner->id,
            'branch_id' => $branch->id,
            'name' => 'Halima',
            'commission_rate' => 10,
        ]);

        $order = $this->makeWingaOrder($owner, $branch, $winga);
        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'paid',
        ]);
        $response->assertOk();

        $commission = WingaCommission::where('order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals('pending', $commission->status);
        $this->assertEquals(100, (float) $commission->commission_amount);
        $this->assertEquals(5, (float) $commission->withholding_tax);
        $this->assertEquals(95, (float) $commission->net_amount);

        $this->assertDatabaseHas('journal_entries', [
            'owner_id' => $owner->id,
            'source_type' => 'order',
        ]);
    }

    public function test_winga_commission_pay_posts_correct_journal(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->seedAccounts($owner);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $winga = Winga::create([
            'owner_id' => $owner->id,
            'branch_id' => $branch->id,
            'name' => 'Halima',
            'commission_rate' => 10,
        ]);

        $order = $this->makeWingaOrder($owner, $branch, $winga);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'paid',
        ])->assertOk();

        $commission = WingaCommission::where('order_id', $order->id)->first();

        $this->withToken($token)->postJson("/api/winga-commissions/{$commission->id}/pay")->assertOk();

        $commission->refresh();
        $this->assertEquals('paid', $commission->status);
        $this->assertNotNull($commission->journal_entry_id);

        $entry = JournalEntry::find($commission->journal_entry_id);
        $lines = $entry->lines->keyBy('account.code');

        $this->assertTrue(isset($lines['2100']));
        $this->assertEquals(100, (float) $lines['2100']->debit);
        $this->assertTrue(isset($lines['1020']));
        $this->assertEquals(95, (float) $lines['1020']->credit);
        $this->assertTrue(isset($lines['2120']));
        $this->assertEquals(5, (float) $lines['2120']->credit);
    }

    public function test_cancelling_paid_winga_order_claws_back_commission(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->seedAccounts($owner);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $winga = Winga::create([
            'owner_id' => $owner->id,
            'branch_id' => $branch->id,
            'name' => 'Halima',
            'commission_rate' => 10,
        ]);

        $order = $this->makeWingaOrder($owner, $branch, $winga);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->patchJson("/api/orders/{$order->id}/status", ['status' => 'paid'])->assertOk();
        $this->withToken($token)->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertDatabaseMissing('winga_commissions', [
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_partial_return_reduces_winga_commission_proportionally(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->seedAccounts($owner);
        $branch = Branch::create(['owner_id' => $owner->id, 'name' => 'Main Store']);
        $winga = Winga::create([
            'owner_id' => $owner->id,
            'branch_id' => $branch->id,
            'name' => 'Halima',
            'commission_rate' => 10,
        ]);

        $order = $this->makeWingaOrder($owner, $branch, $winga);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)->patchJson("/api/orders/{$order->id}/status", ['status' => 'paid'])->assertOk();

        $item = $order->items->first();

        $this->withToken($token)->postJson("/api/orders/{$order->id}/return", [
            'items' => [
                ['order_item_id' => $item->id, 'quantity' => 5],
            ],
        ])->assertOk();

        $commission = WingaCommission::where('order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(50, (float) $commission->commission_amount);
        $this->assertEquals(2.5, (float) $commission->withholding_tax);
        $this->assertEquals(47.5, (float) $commission->net_amount);
    }
}
