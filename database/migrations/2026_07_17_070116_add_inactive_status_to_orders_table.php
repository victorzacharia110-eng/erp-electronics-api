<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('PRAGMA foreign_keys = OFF');

        DB::statement("CREATE TABLE _orders_inactive (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            order_number VARCHAR UNIQUE NOT NULL,
            status VARCHAR NOT NULL DEFAULT 'pending_payment' CHECK (status IN ('pending', 'pending_payment', 'inactive', 'paid', 'processing', 'shipped', 'delivered', 'cancelled')),
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_address_id INTEGER REFERENCES addresses(id),
            handled_by INTEGER REFERENCES users(id),
            notes TEXT,
            created_at TIMESTAMP,
            updated_at TIMESTAMP,
            tracking_number VARCHAR,
            delivery_notes TEXT,
            shipped_at TIMESTAMP,
            delivered_at TIMESTAMP,
            delivery_required TINYINT(1) NOT NULL DEFAULT 0
        )");

        DB::statement("INSERT INTO _orders_inactive (id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at, tracking_number, delivery_notes, shipped_at, delivered_at, delivery_required)
            SELECT id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at, tracking_number, delivery_notes, shipped_at, delivered_at, delivery_required FROM orders");

        DB::statement('DROP TABLE orders');
        DB::statement('ALTER TABLE _orders_inactive RENAME TO orders');
        DB::statement('CREATE INDEX idx_orders_user_id_status ON orders (user_id, status)');

        DB::unprepared('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        DB::unprepared('PRAGMA foreign_keys = OFF');

        DB::statement("CREATE TABLE _orders_inactive (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            order_number VARCHAR UNIQUE NOT NULL,
            status VARCHAR NOT NULL DEFAULT 'pending_payment' CHECK (status IN ('pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled')),
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_address_id INTEGER REFERENCES addresses(id),
            handled_by INTEGER REFERENCES users(id),
            notes TEXT,
            created_at TIMESTAMP,
            updated_at TIMESTAMP,
            tracking_number VARCHAR,
            delivery_notes TEXT,
            shipped_at TIMESTAMP,
            delivered_at TIMESTAMP,
            delivery_required TINYINT(1) NOT NULL DEFAULT 0
        )");

        DB::statement("INSERT INTO _orders_inactive (id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at, tracking_number, delivery_notes, shipped_at, delivered_at, delivery_required)
            SELECT id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at, tracking_number, delivery_notes, shipped_at, delivered_at, delivery_required FROM orders");

        DB::statement('DROP TABLE orders');
        DB::statement('ALTER TABLE _orders_inactive RENAME TO orders');
        DB::statement('CREATE INDEX idx_orders_user_id_status ON orders (user_id, status)');

        DB::unprepared('PRAGMA foreign_keys = ON');
    }
};
