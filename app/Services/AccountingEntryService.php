<?php

namespace App\Services;

use App\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\Commission;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Winga;
use App\Models\WingaCommission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountingEntryService
{
    /**
     * TRA withholding tax (TDS) rate on resident commission payments (%).
     */
    public const WINGA_WHT_RATE = 5.0;

    /**
     * Resolve the configured winga withholding tax rate (TRA compliant, default 5%).
     */
    public function getWingaWhtRate(): float
    {
        return (float) (Setting::where('key', 'winga_wht_rate')->value('value') ?? static::WINGA_WHT_RATE);
    }
    /**
     * VAT rate (%) configured for the business, defaults to TRA standard rate.
     */
    public function getVatRate(?int $ownerId = null): float
    {
        return (float) (Setting::where('key', 'vat_rate')->value('value') ?? 18);
    }

    /**
     * Whether displayed prices already include VAT (standard in Tanzania retail).
     */
    public function pricesIncludeVat(?int $ownerId = null): bool
    {
        $raw = Setting::where('key', 'prices_include_vat')->value('value');

        return filter_var($raw ?? 'true', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Split a gross amount into net and VAT portions.
     */
    public function splitVat(float $gross, float $rate): array
    {
        if ($rate <= 0) {
            return ['net' => round($gross, 2), 'vat' => 0.0];
        }

        if ($this->pricesIncludeVat()) {
            $vat = round($gross * $rate / (100 + $rate), 2);
        } else {
            $vat = round($gross * $rate / 100, 2);
        }

        return ['net' => round($gross - $vat, 2), 'vat' => $vat];
    }

    /**
     * Resolve a system account by code, failing loudly when missing.
     *
     * @throws AccountingException
     */
    public function account(User $owner, string $code): Account
    {
        $account = Account::where('owner_id', $owner->id)->where('code', $code)->first();

        if (!$account) {
            throw new AccountingException(
                "Required system account {$code} is missing for this business. Please contact the owner to run the accounting setup."
            );
        }

        return $account;
    }

    /**
     * Resolve the accounting owner for an order (branch owner when assigned).
     */
    public function ownerForOrder(Order $order, ?User $actor = null): User
    {
        if ($order->branch && $order->branch->owner) {
            return $order->branch->owner;
        }

        if (!$actor) {
            throw new AccountingException('Unable to resolve the accounting owner for this order.');
        }

        return $actor;
    }

    /**
     * Total cost of goods for an order, requiring a cost price on every item.
     *
     * @throws AccountingException when any item has no cost price
     */
    public function computeCogsTotal(Order $order): float
    {
        $total = 0.0;

        foreach ($order->items as $item) {
            $total += $this->requireCostPrice($item) * $item->quantity;
        }

        return $total;
    }

    /**
     * Create and post a journal entry with lines.
     */
    public function createEntry(User $owner, array $lines, array $attributes): JournalEntry
    {
        $entry = JournalEntry::create(array_merge($attributes, [
            'owner_id' => $owner->id,
            'reference' => JournalEntry::generateReference($owner->id),
            'status' => 'posted',
            'posted_at' => now(),
        ]));

        foreach ($lines as $line) {
            $entry->lines()->create($line);
        }

        return $entry;
    }

    /**
     * Post the revenue entry for a paid order (VAT-aware).
     */
    public function postSale(Order $order, User $actor): JournalEntry
    {
        $owner = $this->ownerForOrder($order, $actor);
        $cash = $this->account($owner, '1020');
        $sales = $this->account($owner, '4010');
        $shipping = $this->optional($owner, '4020');
        $vatOutput = $this->optional($owner, '2500');
        $rate = $this->getVatRate($owner->id);

        $subtotalSplit = $this->splitVat((float) $order->subtotal, $rate);
        $shippingSplit = $this->splitVat((float) $order->shipping_cost, $rate);

        // The winga fee is a price increase paid by the customer: part of the taxable sale,
        // recognised as commission expense against a winga commission payable.
        $wingaFee = (float) $order->winga_fee;
        $saleGross = (float) $order->subtotal + $wingaFee;
        $saleSplit = $this->splitVat($saleGross, $rate);

        $lines = [
            ['account_id' => $cash->id, 'debit' => (float) $order->total, 'credit' => 0, 'description' => "Payment for {$order->order_number}"],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => $saleSplit['net'], 'description' => "Sale: {$order->order_number}"],
        ];

        if ($vatOutput && $saleSplit['vat'] > 0) {
            $lines[] = ['account_id' => $vatOutput->id, 'debit' => 0, 'credit' => $saleSplit['vat'], 'description' => "VAT Output: {$order->order_number}"];
        }

        if ($wingaFee > 0) {
            $this->appendWingaLines($lines, $order, $owner);
        }

        if ((float) $order->shipping_cost > 0 && $shipping) {
            $lines[] = ['account_id' => $shipping->id, 'debit' => 0, 'credit' => $shippingSplit['net'], 'description' => "Shipping: {$order->order_number}"];

            if ($vatOutput && $shippingSplit['vat'] > 0) {
                $lines[] = ['account_id' => $vatOutput->id, 'debit' => 0, 'credit' => $shippingSplit['vat'], 'description' => "VAT on shipping: {$order->order_number}"];
            }
        }

        $this->appendCogsLines($lines, $order, $owner);

        return $this->createEntry($owner, $lines, [
            'date' => now()->toDateString(),
            'description' => "Revenue from order {$order->order_number}",
            'posted_by' => $actor->id,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
        ]);
    }

    /**
     * Reverse a fully cancelled sale.
     */
    public function reverseSale(Order $order, User $actor): JournalEntry
    {
        $owner = $this->ownerForOrder($order, $actor);
        $cash = $this->account($owner, '1020');
        $sales = $this->account($owner, '4010');
        $shipping = $this->optional($owner, '4020');
        $vatOutput = $this->optional($owner, '2500');
        $rate = $this->getVatRate($owner->id);

        $subtotalSplit = $this->splitVat((float) $order->subtotal, $rate);
        $shippingSplit = $this->splitVat((float) $order->shipping_cost, $rate);

        $wingaFee = (float) $order->winga_fee;
        $saleGross = (float) $order->subtotal + $wingaFee;
        $saleSplit = $this->splitVat($saleGross, $rate);

        $lines = [
            ['account_id' => $sales->id, 'debit' => $saleSplit['net'], 'credit' => 0, 'description' => "Reversal sale: {$order->order_number}"],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => (float) $order->total, 'description' => "Refund: {$order->order_number}"],
        ];

        if ($vatOutput && $saleSplit['vat'] > 0) {
            $lines[] = ['account_id' => $vatOutput->id, 'debit' => $saleSplit['vat'], 'credit' => 0, 'description' => "Reversal VAT: {$order->order_number}"];
        }

        if ($wingaFee > 0) {
            $this->appendWingaReversalLines($lines, $order, $owner);
        }

        if ((float) $order->shipping_cost > 0 && $shipping) {
            $lines[] = ['account_id' => $shipping->id, 'debit' => $shippingSplit['net'], 'credit' => 0, 'description' => "Reversal shipping: {$order->order_number}"];

            if ($vatOutput && $shippingSplit['vat'] > 0) {
                $lines[] = ['account_id' => $vatOutput->id, 'debit' => $shippingSplit['vat'], 'credit' => 0, 'description' => "Reversal VAT shipping: {$order->order_number}"];
            }
        }

        $this->appendCogsReversalLines($lines, $order, $owner);

        return $this->createEntry($owner, $lines, [
            'date' => now()->toDateString(),
            'description' => "Reversal for cancelled order {$order->order_number}",
            'posted_by' => $actor->id,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
        ]);
    }

    /**
     * Post a partial return: refund returned value, reverse revenue/VAT/COGS, restock inventory.
     */
    public function postReturn(Order $order, OrderItem $item, int $quantity, User $actor): JournalEntry
    {
        $owner = $this->ownerForOrder($order, $actor);
        $cash = $this->account($owner, '1020');
        $sales = $this->account($owner, '4010');
        $inventoryAccount = $this->account($owner, '1200');
        $cogs = $this->account($owner, '5010');
        $vatOutput = $this->optional($owner, '2500');
        $rate = $this->getVatRate($owner->id);

        $ratio = $item->total / max(1, $item->quantity);
        $gross = round($ratio * $quantity, 2);
        $split = $this->splitVat($gross, $rate);
        $cogsAmount = round($this->requireCostPrice($item) * $quantity, 2);

        $productName = $item->productVariant->product?->name ?? $item->productVariant->sku;

        $lines = [
            ['account_id' => $sales->id, 'debit' => $split['net'], 'credit' => 0, 'description' => "Return: {$productName} ({$order->order_number})"],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => $gross, 'description' => "Refund: {$order->order_number}"],
            ['account_id' => $inventoryAccount->id, 'debit' => $cogsAmount, 'credit' => 0, 'description' => "Inventory restocked: {$order->order_number}"],
            ['account_id' => $cogs->id, 'debit' => 0, 'credit' => $cogsAmount, 'description' => "COGS reversal: {$order->order_number}"],
        ];

        if ($vatOutput && $split['vat'] > 0) {
            $lines[] = ['account_id' => $vatOutput->id, 'debit' => $split['vat'], 'credit' => 0, 'description' => "VAT reversal: {$order->order_number}"];
        }

        return $this->createEntry($owner, $lines, [
            'date' => now()->toDateString(),
            'description' => "Return on order {$order->order_number}",
            'posted_by' => $actor->id,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
        ]);
    }

    /**
     * Post a journal entry for an inventory adjustment / damage / opening stock.
     */
    public function postInventoryAdjustment(User $owner, $variant, int $quantityChange, string $type, ?string $notes, ?User $actor = null): JournalEntry
    {
        $inventoryAccount = $this->account($owner, '1200');
        $adjustmentAccount = $this->account($owner, '5100');
        $capitalAccount = $this->optional($owner, '3010');

        $unitCost = (float) ($variant->cost_price ?? 0);
        $productName = $variant->product?->name ?? $variant->sku;

        if ($unitCost <= 0) {
            throw new AccountingException("Cannot journal inventory adjustment — cost price is missing for {$productName}.");
        }

        $amount = round($unitCost * abs($quantityChange), 2);

        if ($quantityChange < 0) {
            $lines = [
                ['account_id' => $adjustmentAccount->id, 'debit' => $amount, 'credit' => 0, 'description' => $notes ?? "Stock {$type} ({$variant->sku})"],
                ['account_id' => $inventoryAccount->id, 'debit' => 0, 'credit' => $amount, 'description' => $notes ?? "Stock {$type} ({$variant->sku})"],
            ];
        } elseif ($type === 'opening' && $capitalAccount) {
            $lines = [
                ['account_id' => $inventoryAccount->id, 'debit' => $amount, 'credit' => 0, 'description' => "Opening stock ({$variant->sku})"],
                ['account_id' => $capitalAccount->id, 'debit' => 0, 'credit' => $amount, 'description' => "Owner capital for opening stock ({$variant->sku})"],
            ];
        } else {
            $lines = [
                ['account_id' => $inventoryAccount->id, 'debit' => $amount, 'credit' => 0, 'description' => $notes ?? "Stock {$type} ({$variant->sku})"],
                ['account_id' => $adjustmentAccount->id, 'debit' => 0, 'credit' => $amount, 'description' => $notes ?? "Stock {$type} ({$variant->sku})"],
            ];
        }

        return $this->createEntry($owner, $lines, [
            'date' => now()->toDateString(),
            'description' => 'Inventory adjustment: ' . ($notes ?? $type),
            'posted_by' => $actor?->id ?? $owner->id,
            'source_type' => 'inventory',
            'source_id' => (string) $variant->id,
        ]);
    }

    /**
     * Create a commission record for the order handler.
     */
    public function createCommission(Order $order, User $actor, ?float $cogsTotal = null): ?Commission
    {
        $owner = $this->ownerForOrder($order, $actor);
        $handler = User::with('employeeProfile')->find($order->handled_by ?? $actor->id);

        if (!$handler || $handler->role !== 'employee' || !$handler->employeeProfile || $handler->employeeProfile->commission_rate <= 0) {
            return null;
        }

        $rate = $handler->employeeProfile->commission_rate;
        $costAmount = $cogsTotal ?? $this->computeCogsTotal($order);
        $profitAmount = (float) $order->subtotal - $costAmount;
        $commissionAmount = max(0, round($profitAmount * ($rate / 100), 2));

        if ($commissionAmount <= 0) {
            return null;
        }

        return Commission::create([
            'owner_id' => $owner->id,
            'employee_id' => $handler->id,
            'order_id' => $order->id,
            'order_amount' => $order->subtotal,
            'cost_amount' => $costAmount,
            'profit_amount' => $profitAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
        ]);
    }

    /**
     * Create the winga commission record for an order tagged with a winga.
     * The gross commission equals the winga fee charged to the customer; TRA
     * withholding tax (TDS) is computed at the configured rate.
     */
    public function createWingaCommission(Order $order): ?WingaCommission
    {
        $wingaFee = (float) $order->winga_fee;
        $winga = $order->winga;

        if (!$winga || $wingaFee <= 0 || $winga->status !== 'active') {
            return null;
        }

        $whtRate = $this->getWingaWhtRate();
        $withholdingTax = round($wingaFee * $whtRate / 100, 2);

        return WingaCommission::create([
            'winga_id' => $winga->id,
            'order_id' => $order->id,
            'order_amount' => $order->subtotal,
            'commission_rate' => $winga->commission_rate,
            'commission_amount' => $wingaFee,
            'withholding_tax' => $withholdingTax,
            'net_amount' => round($wingaFee - $withholdingTax, 2),
            'status' => 'pending',
        ]);
    }

    /**
     * Reverse winga commissions on a cancelled order: delete pending, claw back paid.
     */
    public function reverseWingaCommissions(Order $order, User $actor): void
    {
        $owner = $this->ownerForOrder($order, $actor);
        $pending = WingaCommission::where('order_id', $order->id)->where('status', 'pending')->get();
        $paid = WingaCommission::where('order_id', $order->id)->where('status', 'paid')->get();

        foreach ($pending as $commission) {
            $commission->delete();
        }

        foreach ($paid as $commission) {
            $this->clawbackWingaCommission($commission, $owner, $actor);
        }
    }

    /**
     * Reduce winga commissions proportionally for a partial return, posting an
     * adjustment that reverses the accrued expense/payable for the returned share.
     */
    public function adjustWingaCommissionForReturn(Order $order, float $returnedAmount, User $actor): void
    {
        $total = (float) $order->subtotal;

        if ($total <= 0) {
            return;
        }

        $ratio = min(1, $returnedAmount / $total);
        $returnedWingaFee = round((float) $order->winga_fee * $ratio, 2);

        if ($returnedWingaFee <= 0) {
            return;
        }

        $owner = $this->ownerForOrder($order, $actor);

        foreach (WingaCommission::where('order_id', $order->id)->get() as $commission) {
            if ($commission->status === 'pending') {
                $newAmount = round(max(0, $commission->commission_amount - $returnedWingaFee), 2);
                $newWht = round(max(0, $commission->withholding_tax - round($returnedWingaFee * $this->getWingaWhtRate() / 100, 2)), 2);
                $newNet = round(max(0, $newAmount - $newWht), 2);

                if ($newAmount <= 0) {
                    $commission->delete();
                } else {
                    $commission->update([
                        'order_amount' => round(max(0, $commission->order_amount - $returnedAmount), 2),
                        'commission_amount' => $newAmount,
                        'withholding_tax' => $newWht,
                        'net_amount' => $newNet,
                    ]);
                }

                $this->postWingaReturnAdjustment($owner, $order, $returnedWingaFee, $actor);
            } elseif ($commission->status === 'paid') {
                $this->clawbackWingaCommission($commission, $owner, $actor, $returnedWingaFee);
            }
        }
    }

    /**
     * Post an entry that reverses the accrued winga commission for a returned amount:
     * debit Winga Commission Payable, credit Commission Expense.
     */
    private function postWingaReturnAdjustment(User $owner, Order $order, float $amount, User $actor): void
    {
        $payable = $this->account($owner, '2100');
        $expense = $this->account($owner, '5110');

        $this->createEntry($owner, [
            ['account_id' => $payable->id, 'debit' => $amount, 'credit' => 0, 'description' => "Winga commission reversal for returned items ({$order->order_number})"],
            ['account_id' => $expense->id, 'debit' => 0, 'credit' => $amount, 'description' => "Winga commission reversal for returned items ({$order->order_number})"],
        ], [
            'date' => now()->toDateString(),
            'description' => "Winga commission adjustment for returned order {$order->order_number}",
            'posted_by' => $actor->id,
            'source_type' => 'winga_commission',
            'source_id' => (string) $order->id,
        ]);
    }

    /**
     * Reverse a paid winga commission payout (money and withheld tax come back).
     */
    private function clawbackWingaCommission(WingaCommission $commission, User $owner, User $actor, ?float $amount = null): void
    {
        $amount = $amount ?? (float) $commission->commission_amount;
        $taxShare = round($amount * $this->getWingaWhtRate() / 100, 2);
        $netShare = round($amount - $taxShare, 2);

        if ($amount <= 0) {
            return;
        }

        $payable = $this->account($owner, '2100');
        $cash = $this->account($owner, '1020');
        $whtPayable = $this->account($owner, '2120');

        $this->createEntry($owner, [
            ['account_id' => $cash->id, 'debit' => $netShare, 'credit' => 0, 'description' => "Winga commission clawback for order #{$commission->order_id}"],
            ['account_id' => $whtPayable->id, 'debit' => $taxShare, 'credit' => 0, 'description' => "WHT clawback for order #{$commission->order_id}"],
            ['account_id' => $payable->id, 'debit' => 0, 'credit' => $amount, 'description' => "Winga commission reversal for order #{$commission->order_id}"],
        ], [
            'date' => now()->toDateString(),
            'description' => "Winga commission reversal for cancelled/returned order #{$commission->order_id}",
            'posted_by' => $actor->id,
            'source_type' => 'winga_commission',
            'source_id' => (string) $commission->id,
        ]);

        if ($amount >= (float) $commission->commission_amount) {
            $commission->update(['status' => 'reversed']);
        }
    }

    /**
     * Accrue a winga commission at sale time: expense debit / payable credit.
     */
    private function appendWingaLines(array &$lines, Order $order, User $owner): void
    {
        $expense = $this->account($owner, '5110');
        $payable = $this->account($owner, '2100');
        $fee = (float) $order->winga_fee;

        $lines[] = ['account_id' => $expense->id, 'debit' => $fee, 'credit' => 0, 'description' => "Winga commission: {$order->order_number}"];
        $lines[] = ['account_id' => $payable->id, 'debit' => 0, 'credit' => $fee, 'description' => "Winga commission payable: {$order->order_number}"];
    }

    /**
     * Reverse the accrued winga commission: payable debit / expense credit.
     */
    private function appendWingaReversalLines(array &$lines, Order $order, User $owner): void
    {
        $expense = $this->account($owner, '5110');
        $payable = $this->account($owner, '2100');
        $fee = (float) $order->winga_fee;

        $lines[] = ['account_id' => $payable->id, 'debit' => $fee, 'credit' => 0, 'description' => "Winga commission reversal: {$order->order_number}"];
        $lines[] = ['account_id' => $expense->id, 'debit' => 0, 'credit' => $fee, 'description' => "Winga commission reversal: {$order->order_number}"];
    }

    /**
     * Reverse commissions on a cancelled order: delete pending, claw back paid ones.
     */
    public function reverseCommissions(Order $order, User $actor): void
    {
        $owner = $this->ownerForOrder($order, $actor);
        $pending = Commission::where('order_id', $order->id)->where('status', 'pending')->get();
        $paid = Commission::where('order_id', $order->id)->where('status', 'paid')->get();

        foreach ($pending as $commission) {
            $commission->delete();
        }

        foreach ($paid as $commission) {
            $this->clawbackCommission($commission, $owner, $actor);
        }
    }

    /**
     * Reduce commissions proportionally for a partial return.
     */
    public function adjustCommissionForReturn(Order $order, float $returnedAmount, float $returnedCogs, User $actor): void
    {
        $owner = $this->ownerForOrder($order, $actor);
        $total = (float) $order->subtotal;

        if ($total <= 0) {
            return;
        }

        $ratio = min(1, $returnedAmount / $total);

        foreach (Commission::where('order_id', $order->id)->get() as $commission) {
            if ($commission->status === 'pending') {
                $newAmount = round(max(0, $commission->commission_amount - $commission->commission_amount * $ratio), 2);

                if ($newAmount <= 0) {
                    $commission->delete();
                } else {
                    $commission->update([
                        'order_amount' => round(max(0, $commission->order_amount - $returnedAmount), 2),
                        'cost_amount' => round(max(0, $commission->cost_amount - $returnedCogs), 2),
                        'profit_amount' => round(max(0, $commission->profit_amount - ($returnedAmount - $returnedCogs)), 2),
                        'commission_amount' => $newAmount,
                    ]);
                }
            } elseif ($commission->status === 'paid') {
                $clawback = round($commission->commission_amount * $ratio, 2);

                if ($clawback > 0) {
                    $this->clawbackCommission($commission, $owner, $actor, $clawback);
                }
            }
        }
    }

    /**
     * Reverse a paid commission payout (money comes back to the business).
     */
    private function clawbackCommission(Commission $commission, User $owner, User $actor, ?float $amount = null): void
    {
        $amount = $amount ?? (float) $commission->commission_amount;

        if ($amount <= 0) {
            return;
        }

        $cash = $this->account($owner, '1020');
        $expense = $this->optional($owner, '5110') ?? $this->account($owner, '5050');

        $entry = $this->createEntry($owner, [
            ['account_id' => $cash->id, 'debit' => $amount, 'credit' => 0, 'description' => "Commission clawback for order #{$commission->order_id}"],
            ['account_id' => $expense->id, 'debit' => 0, 'credit' => $amount, 'description' => "Commission reversal for order #{$commission->order_id}"],
        ], [
            'date' => now()->toDateString(),
            'description' => "Commission reversal for cancelled/returned order #{$commission->order_id}",
            'posted_by' => $actor->id,
            'source_type' => 'commission',
            'source_id' => (string) $commission->id,
        ]);

        if ($amount >= (float) $commission->commission_amount) {
            $commission->update(['status' => 'reversed']);
        }
    }

    /**
     * Year-end closing entry: zero out revenue/expense accounts into Retained Earnings.
     */
    public function closeYear(User $owner, int $year): JournalEntry
    {
        $retained = $this->account($owner, '3020');
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end = $start->copy()->endOfYear();

        $lines = [];
        $net = 0.0;

        foreach (Account::where('owner_id', $owner->id)->where('type', 'revenue')->where('is_active', true)->get() as $account) {
            $balance = $this->accountBalance($owner, $account, $start, $end);
            if (abs($balance) < 0.01) {
                continue;
            }
            $lines[] = ['account_id' => $account->id, 'debit' => round($balance, 2), 'credit' => 0, 'description' => "Closing {$account->name} to retained earnings"];
            $net += $balance;
        }

        foreach (Account::where('owner_id', $owner->id)->where('type', 'expense')->where('is_active', true)->get() as $account) {
            $balance = $this->accountBalance($owner, $account, $start, $end);
            if (abs($balance) < 0.01) {
                continue;
            }
            $lines[] = ['account_id' => $account->id, 'debit' => 0, 'credit' => round($balance, 2), 'description' => "Closing {$account->name} to retained earnings"];
            $net -= $balance;
        }

        if (empty($lines)) {
            throw new AccountingException('No revenue or expense balances to close for the selected year.');
        }

        if ($net >= 0) {
            $lines[] = ['account_id' => $retained->id, 'debit' => 0, 'credit' => round($net, 2), 'description' => "Net income for {$year}"];
        } else {
            $lines[] = ['account_id' => $retained->id, 'debit' => round(abs($net), 2), 'credit' => 0, 'description' => "Net loss for {$year}"];
        }

        return $this->createEntry($owner, $lines, [
            'date' => $end->toDateString(),
            'description' => "Year-end closing entry for {$year}",
            'posted_by' => $owner->id,
            'source_type' => 'year_close',
            'source_id' => (string) $year,
        ]);
    }

    private function requireCostPrice(OrderItem $item): float
    {
        $cost = (float) ($item->productVariant->cost_price ?? 0);

        if ($cost <= 0) {
            $name = $item->productVariant->product?->name ?? $item->productVariant->sku ?? 'product';
            throw new AccountingException(
                "Cannot book cost of goods sold for {$name} — cost price is missing. Set the cost price before confirming this sale."
            );
        }

        return $cost;
    }

    private function optional(User $owner, string $code): ?Account
    {
        return Account::where('owner_id', $owner->id)->where('code', $code)->first();
    }

    private function appendCogsLines(array &$lines, Order $order, User $owner): void
    {
        $cogs = $this->account($owner, '5010');
        $inventory = $this->account($owner, '1200');
        $cogsTotal = $this->computeCogsTotal($order);

        if ($cogsTotal <= 0) {
            return;
        }

        $lines[] = ['account_id' => $cogs->id, 'debit' => $cogsTotal, 'credit' => 0, 'description' => "COGS: {$order->order_number}"];
        $lines[] = ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $cogsTotal, 'description' => "Inventory out: {$order->order_number}"];
    }

    private function appendCogsReversalLines(array &$lines, Order $order, User $owner): void
    {
        $cogs = $this->account($owner, '5010');
        $inventory = $this->account($owner, '1200');
        $cogsTotal = $this->computeCogsTotal($order);

        if ($cogsTotal <= 0) {
            return;
        }

        $lines[] = ['account_id' => $inventory->id, 'debit' => $cogsTotal, 'credit' => 0, 'description' => "Inventory in: {$order->order_number}"];
        $lines[] = ['account_id' => $cogs->id, 'debit' => 0, 'credit' => $cogsTotal, 'description' => "Reversal COGS: {$order->order_number}"];
    }

    private function accountBalance(User $owner, Account $account, Carbon $from, Carbon $to): float
    {
        $totals = JournalLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('owner_id', $owner->id)
                ->where('status', 'posted')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')
            ->first();

        $debit = (float) ($totals->d ?? 0);
        $credit = (float) ($totals->c ?? 0);

        return $account->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
    }
}
