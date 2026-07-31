<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'owner_id', 'name', 'contact_person', 'phone', 'email',
        'address', 'city', 'country', 'products_description',
        'notes', 'is_active', 'business_type', 'tin_number',
        'vat_number', 'business_registration_number',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class, 'supplier_id'); }
    public function documents(): HasMany { return $this->hasMany(SupplierDocument::class); }
    public function scopeForOwner($query, $ownerId) { return $query->where('owner_id', $ownerId); }
    public function scopeActive($query) { return $query->where('is_active', true); }
}
