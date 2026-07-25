<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('PRAGMA foreign_keys = OFF');

        DB::statement("CREATE TABLE _orders_pending (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            order_number VARCHAR UNIQUE NOT NULL,
            status VARCHAR NOT NULL DEFAULT 'pending_payment' CHECK (status IN ('pending', 'pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled')),
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_address_id INTEGER REFERENCES addresses(id),
            handled_by INTEGER REFERENCES users(id),
            notes TEXT,
            created_at TIMESTAMP,
            updated_at TIMESTAMP
        )");

        DB::statement("INSERT INTO _orders_pending (id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at)
            SELECT id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at FROM orders");

        DB::statement('DROP TABLE orders');
        DB::statement('ALTER TABLE _orders_pending RENAME TO orders');
        DB::statement('CREATE INDEX idx_orders_user_id_status ON orders (user_id, status)');

        DB::unprepared('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        DB::unprepared('PRAGMA foreign_keys = OFF');

        DB::statement("CREATE TABLE _orders_pending (
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
            updated_at TIMESTAMP
        )");

        DB::statement("INSERT INTO _orders_pending (id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at)
            SELECT id, user_id, order_number, status, subtotal, shipping_cost, total, shipping_address_id, handled_by, notes, created_at, updated_at FROM orders");

        DB::statement('DROP TABLE orders');
        DB::statement('ALTER TABLE _orders_pending RENAME TO orders');
        DB::statement('CREATE INDEX idx_orders_user_id_status ON orders (user_id, status)');

        DB::unprepared('PRAGMA foreign_keys = ON');
    }
};
