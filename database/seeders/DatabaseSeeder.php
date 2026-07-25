<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create users
        $owner = User::create([
            'name' => 'Victor Zacharia',
            'email' => 'victorzacharia110@gmail.com',
            'phone' => '+255700000000',
            'password' => Hash::make('P@ssword@electroshop'),
            'role' => 'owner',
            'password_changed_at' => now(),
        ]);

        $employee = User::create([
            'name' => 'Mathew Zacharia',
            'email' => 'mathewzacharia@gmail.com',
            'phone' => '+255700000001',
            'password' => Hash::make('MATHEW ZACHARIA'),
            'role' => 'employee',
            'password_changed_at' => null,
        ]);

        $customer = User::create([
            'name' => 'Zacharia Kinyula',
            'email' => 'zachariakinyula@gmail.com',
            'phone' => '+255700000002',
            'password' => Hash::make('Password'),
            'role' => 'customer',
            'password_changed_at' => now(),
        ]);

        // Create categories
        $phones = Category::create([
            'name' => 'Phones',
            'slug' => 'phones',
            'description' => 'Smartphones and feature phones',
            'image' => null,
            'sort_order' => 1,
        ]);

        $accessories = Category::create([
            'name' => 'Accessories',
            'slug' => 'accessories',
            'description' => 'Phone cases, chargers, cables and more',
            'image' => null,
            'sort_order' => 2,
        ]);

        $audio = Category::create([
            'name' => 'Audio',
            'slug' => 'audio',
            'description' => 'Earphones, headphones and speakers',
            'image' => null,
            'sort_order' => 3,
        ]);

        $wearables = Category::create([
            'name' => 'Wearables',
            'slug' => 'wearables',
            'description' => 'Smartwatches and fitness trackers',
            'image' => null,
            'sort_order' => 4,
        ]);

        // Create products
        $products = [
            // Phones
            [
                'name' => 'Samsung Galaxy A15',
                'sku' => 'SAM-GAL-A15',
                'description' => 'Samsung Galaxy A15 with 6.5" Super AMOLED display, 50MP camera, and 5000mAh battery.',
                'price' => 450000,
                'cost_price' => 350000,
                'image' => '1784264085_samsunga15.webp',
                'category_id' => $phones->id,
                'brand' => 'Samsung',
                'variants' => [
                    ['sku' => 'SAM-GAL-A15-128-BLK', 'color' => 'Black', 'storage' => '128GB', 'price' => 450000, 'qty' => 25],
                    ['sku' => 'SAM-GAL-A15-128-BLU', 'color' => 'Blue', 'storage' => '128GB', 'price' => 450000, 'qty' => 20],
                ],
            ],
            [
                'name' => 'iPhone 15',
                'sku' => 'APL-IPH-15',
                'description' => 'Apple iPhone 15 with A16 Bionic chip, 48MP camera, and Dynamic Island.',
                'price' => 1800000,
                'cost_price' => 1500000,
                'image' => '1784132686_iphone15pro256gb.webp',
                'category_id' => $phones->id,
                'brand' => 'Apple',
                'variants' => [
                    ['sku' => 'APL-IPH-15-128-BLK', 'color' => 'Black', 'storage' => '128GB', 'price' => 1800000, 'qty' => 15],
                    ['sku' => 'APL-IPH-15-256-WHT', 'color' => 'White', 'storage' => '256GB', 'price' => 2100000, 'qty' => 10],
                ],
            ],
            [
                'name' => 'Tecno Spark 20 Pro',
                'sku' => 'TEC-SP20P',
                'description' => 'Tecno Spark 20 Pro with 108MP camera, 6.78" display, and 5000mAh battery.',
                'price' => 350000,
                'cost_price' => 280000,
                'image' => '1784133438_tecnospark20pro.webp',
                'category_id' => $phones->id,
                'brand' => 'Tecno',
                'variants' => [
                    ['sku' => 'TEC-SP20P-256-GRN', 'color' => 'Green', 'storage' => '256GB', 'price' => 350000, 'qty' => 30],
                ],
            ],
            [
                'name' => 'Xiaomi Redmi Note 13',
                'sku' => 'XIA-RN13',
                'description' => 'Xiaomi Redmi Note 13 with 108MP camera, 6.67" AMOLED display.',
                'price' => 420000,
                'cost_price' => 330000,
                'image' => '1784133520_xiaomi-redmi-note-13sku-xia-rn13xiaomi.webp',
                'category_id' => $phones->id,
                'brand' => 'Xiaomi',
                'variants' => [
                    ['sku' => 'XIA-RN13-128-BLK', 'color' => 'Black', 'storage' => '128GB', 'price' => 420000, 'qty' => 20],
                    ['sku' => 'XIA-RN13-256-BLU', 'color' => 'Blue', 'storage' => '256GB', 'price' => 480000, 'qty' => 15],
                ],
            ],
            // Accessories
            [
                'name' => 'Fast Charger 25W',
                'sku' => 'ACC-CHG-25W',
                'description' => 'Universal 25W USB-C fast charger compatible with Samsung, Apple, and more.',
                'price' => 35000,
                'cost_price' => 20000,
                'image' => '1784133653_25wsuperfastcharger.jpeg',
                'category_id' => $accessories->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'ACC-CHG-25W-WHT', 'color' => 'White', 'storage' => null, 'price' => 35000, 'qty' => 100],
                    ['sku' => 'ACC-CHG-25W-BLK', 'color' => 'Black', 'storage' => null, 'price' => 35000, 'qty' => 80],
                ],
            ],
            [
                'name' => 'Silicone Phone Case',
                'sku' => 'ACC-CSE-SIL',
                'description' => 'Premium silicone phone case with shock absorption. Fits most 6.5" phones.',
                'price' => 15000,
                'cost_price' => 5000,
                'image' => '1784133848_siliconcases.jpeg',
                'category_id' => $accessories->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'ACC-CSE-SIL-BLK', 'color' => 'Black', 'storage' => null, 'price' => 15000, 'qty' => 200],
                    ['sku' => 'ACC-CSE-SIL-BLU', 'color' => 'Blue', 'storage' => null, 'price' => 15000, 'qty' => 150],
                    ['sku' => 'ACC-CSE-SIL-RED', 'color' => 'Red', 'storage' => null, 'price' => 15000, 'qty' => 100],
                ],
            ],
            [
                'name' => 'Tempered Glass Screen Protector',
                'sku' => 'ACC-SCR-TMP',
                'description' => '9H hardness tempered glass screen protector with oleophobic coating.',
                'price' => 10000,
                'cost_price' => 3000,
                'image' => '1784134065_temperedglassscreenprotector.jpeg',
                'category_id' => $accessories->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'ACC-SCR-TMP-UNI', 'color' => 'Clear', 'storage' => null, 'price' => 10000, 'qty' => 300],
                ],
            ],
            [
                'name' => 'USB-C Cable 2m',
                'sku' => 'ACC-CBL-USBC',
                'description' => 'Durable braided USB-C to USB-C cable, 2 meters length.',
                'price' => 12000,
                'cost_price' => 5000,
                'image' => '1784134196_usb-c-cable2m.jpeg',
                'category_id' => $accessories->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'ACC-CBL-USBC-BLK', 'color' => 'Black', 'storage' => null, 'price' => 12000, 'qty' => 150],
                ],
            ],
            // Audio
            [
                'name' => 'Wireless Earbuds Pro',
                'sku' => 'AUD-ERB-PRO',
                'description' => 'Bluetooth 5.3 wireless earbuds with ANC and 30h battery life.',
                'price' => 65000,
                'cost_price' => 35000,
                'image' => '1784134290_wirelessearbuds.jpeg',
                'category_id' => $audio->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'AUD-ERB-PRO-WHT', 'color' => 'White', 'storage' => null, 'price' => 65000, 'qty' => 50],
                    ['sku' => 'AUD-ERB-PRO-BLK', 'color' => 'Black', 'storage' => null, 'price' => 65000, 'qty' => 40],
                ],
            ],
            [
                'name' => 'Bluetooth Speaker Mini',
                'sku' => 'AUD-SPK-MINI',
                'description' => 'Portable mini Bluetooth speaker with 10W output and IPX5 waterproof.',
                'price' => 45000,
                'cost_price' => 25000,
                'image' => '1784134411_bluetoothspeakermini.jpeg',
                'category_id' => $audio->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'AUD-SPK-MINI-BLK', 'color' => 'Black', 'storage' => null, 'price' => 45000, 'qty' => 35],
                ],
            ],
            // Wearables
            [
                'name' => 'Smart Watch S8',
                'sku' => 'WRT-SW-S8',
                'description' => 'Smart watch with heart rate monitor, blood oxygen, and 7-day battery.',
                'price' => 85000,
                'cost_price' => 45000,
                'image' => '1784134494_smartwatchs8.jpeg',
                'category_id' => $wearables->id,
                'brand' => 'Generic',
                'variants' => [
                    ['sku' => 'WRT-SW-S8-BLK', 'color' => 'Black', 'storage' => null, 'price' => 85000, 'qty' => 30],
                    ['sku' => 'WRT-SW-S8-SLV', 'color' => 'Silver', 'storage' => null, 'price' => 85000, 'qty' => 20],
                ],
            ],
        ];

        foreach ($products as $productData) {
            $variants = $productData['variants'];
            unset($productData['variants']);

            $product = Product::create($productData);

            foreach ($variants as $variantData) {
                $qty = $variantData['qty'];
                unset($variantData['qty']);

                $variant = $product->variants()->create($variantData);
                $variant->inventory()->create([
                    'quantity_on_hand' => $qty,
                    'reorder_level' => 10,
                ]);
            }
        }

        // Default settings
        Setting::create(['key' => 'clickpesa_enabled', 'value' => 'false', 'type' => 'boolean']);

        // Default payment providers
        PaymentProvider::create(['name' => 'M-Pesa', 'slug' => 'mpesa', 'number' => '0794770268', 'icon' => 'fas fa-mobile-screen', 'enabled' => true, 'sort_order' => 1]);
        PaymentProvider::create(['name' => 'Airtel Money', 'slug' => 'airtel', 'number' => '0683870268', 'icon' => 'fas fa-signal', 'enabled' => true, 'sort_order' => 2]);
        PaymentProvider::create(['name' => 'Mixx by Yas', 'slug' => 'mixx_by_yas', 'number' => '0703870268', 'icon' => 'fas fa-water', 'enabled' => true, 'sort_order' => 3]);
        PaymentProvider::create(['name' => 'Halopesa', 'slug' => 'halopesa', 'number' => '0632870268', 'icon' => 'fas fa-bolt', 'enabled' => true, 'sort_order' => 4]);

        // Seed superadmin and owner profiles (must run after owner is created)
        $this->call(SuperadminSeeder::class);

        // Seed default chart of accounts for owner
        $this->call(AccountingSeeder::class);
    }
}
