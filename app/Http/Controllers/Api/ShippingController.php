<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingRule;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function index(Request $request)
    {
        return ShippingRule::where('owner_id', $request->ownerId())
            ->orderBy('from_city')->orderBy('to_city')->get();
    }

    public function calculate(Request $request)
    {
        $request->validate([
            'from_city' => 'required|string',
            'to_city'   => 'required|string',
            'subtotal'  => 'required|numeric|min:0',
        ]);

        $subtotal = (float) $request->subtotal;

        $rules = ShippingRule::where('enabled', true);

        if ($business = \App\Support\Tenant::bySlug($request->query('business'))) {
            $rules->where('owner_id', $business->owner_id);
        }

        $rules = $rules->where(function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->whereRaw('LOWER(from_city) = ?', [strtolower($request->from_city)])
                       ->whereRaw('LOWER(to_city) = ?', [strtolower($request->to_city)]);
                })->orWhere(function ($q2) {
                    $q2->where('from_city', '*')->orWhere('from_city', '');
                })->orWhere(function ($q2) {
                    $q2->where('to_city', '*')->orWhere('to_city', '');
                });
            })
            ->get();

        if ($rules->isEmpty()) {
            return response()->json([
                'matched' => false,
                'cost'    => 5000,
                'message' => 'Default shipping rate applies',
            ]);
        }

        $bestCost = null;

        foreach ($rules as $rule) {
            $cost = $rule->calculateCost($subtotal);
            if ($bestCost === null || $cost < $bestCost) {
                $bestCost = $cost;
            }
        }

        return response()->json([
            'matched' => true,
            'cost'    => $bestCost,
            'rules'   => $rules->map(fn($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'from_city'   => $r->from_city,
                'to_city'     => $r->to_city,
                'base_cost'   => (float) $r->base_cost,
                'value_rules' => $r->value_rules,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'from_city'   => 'required|string|max:255',
            'to_city'     => 'required|string|max:255',
            'base_cost'   => 'required|numeric|min:0',
            'value_rules' => 'nullable|array',
            'value_rules.*.min_value'     => 'nullable|numeric|min:0',
            'value_rules.*.max_value'     => 'nullable|numeric|min:0',
            'value_rules.*.adjusted_cost' => 'nullable|numeric|min:0',
            'enabled'     => 'boolean',
        ]);

        $data['enabled'] = $data['enabled'] ?? true;
        $data['value_rules'] = $this->cleanValueRules($data['value_rules'] ?? null);
        $data['owner_id'] = $request->ownerId();

        $rule = ShippingRule::create($data);

        return response()->json($rule, 201);
    }

    public function update(Request $request, int $id)
    {
        $rule = ShippingRule::where('owner_id', $request->ownerId())->findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'from_city'   => 'sometimes|string|max:255',
            'to_city'     => 'sometimes|string|max:255',
            'base_cost'   => 'sometimes|numeric|min:0',
            'value_rules' => 'nullable|array',
            'value_rules.*.min_value'     => 'nullable|numeric|min:0',
            'value_rules.*.max_value'     => 'nullable|numeric|min:0',
            'value_rules.*.adjusted_cost' => 'nullable|numeric|min:0',
            'enabled'     => 'boolean',
        ]);

        if (array_key_exists('value_rules', $data)) {
            $data['value_rules'] = $this->cleanValueRules($data['value_rules'] ?? null);
        }

        $rule->update($data);

        return response()->json($rule);
    }

    public function destroy(Request $request, int $id)
    {
        $rule = ShippingRule::where('owner_id', $request->ownerId())->findOrFail($id);
        $rule->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function cleanValueRules(?array $rules): ?array
    {
        if (empty($rules)) return null;

        $clean = [];
        foreach ($rules as $r) {
            if (empty($r['min_value']) && empty($r['max_value']) && empty($r['adjusted_cost'])) continue;
            $clean[] = [
                'min_value'     => $r['min_value'] ?? null,
                'max_value'     => $r['max_value'] ?? null,
                'adjusted_cost' => $r['adjusted_cost'] ?? null,
            ];
        }

        return count($clean) ? $clean : null;
    }
}
