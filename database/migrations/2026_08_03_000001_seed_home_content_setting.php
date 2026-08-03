<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $content = [
            'en' => [
                'heroBadge' => 'New Arrivals',
                'heroTitle' => 'Quality Electronics at',
                'heroTitleHighlight' => 'Affordable Prices',
                'heroDesc' => 'Find the latest phones, accessories, and gadgets. Fast delivery across Tanzania.',
                'shopNow' => 'Shop Now',
                'viewCatalog' => 'View Catalog',
                'freeDelivery' => 'Free Delivery',
                'freeDeliveryDesc' => 'On orders over TSh 100,000',
                'securePayment' => 'Secure Payment',
                'securePaymentDesc' => '100% secure transactions',
                'easyReturns' => 'Easy Returns',
                'easyReturnsDesc' => '7-day return policy',
                'support247' => '24/7 Support',
                'support247Desc' => 'Dedicated customer service',
                'shopByCategory' => 'Shop by Category',
                'browseCategories' => 'Browse our wide range of electronics',
                'productsCount' => '{count} products',
                'categoriesComingSoon' => 'Categories coming soon',
                'newArrivals' => 'New Arrivals',
                'newArrivalsDesc' => 'Check out our latest products',
                'noProductsYet' => 'No products yet. Check back soon!',
                'viewMoreProducts' => 'View More Products',
                'hotDeals' => 'Hot Deals',
                'hotDealsTitle' => 'Up to 30% Off on Selected Items',
                'hotDealsDesc' => 'Limited time offer on popular electronics',
                'shopTheSale' => 'Shop the Sale',
                'hotSelling' => 'Hot-Selling Products',
                'hotSellingDesc' => 'Most popular items loved by our customers',
                'viewAllProducts' => 'View All Products',
                'createAccount' => 'Create Account',
                'createAccountDesc' => 'Sign up for exclusive deals and order tracking',
                'register' => 'Register',
                'mobileMoney' => 'Mobile Money',
                'mobileMoneyDesc' => 'Pay with M-Pesa, Tigo Pesa, or Airtel Money',
                'fastDeliveryTitle' => 'Fast Delivery',
                'fastDeliveryDesc' => 'Same-day delivery in Dar es Salaam',
                'dirBadge' => 'Our Stores',
                'dirTitle' => 'Shop from our partner stores',
                'dirSubtitle' => 'Browse electronics from all our stores in one place. Choose a store to start shopping.',
                'dirProductsCount' => '{count} products',
                'dirVisitStore' => 'Visit Store',
                'dirEmpty' => 'No stores are available right now. Please check back soon.',
                'dirNew' => 'New',
                'dirNewArrivals' => '{count} new items in stock',
            ],
            'sw' => [
                'heroBadge' => 'Mpya',
                'heroTitle' => 'Elektroniki Bora kwa',
                'heroTitleHighlight' => 'Bei Nafuu',
                'heroDesc' => 'Pata simu, vifaa, na gadgets za hivi karibuni. Usafirishaji wa haraka kote Tanzania.',
                'shopNow' => 'Nunua Sasa',
                'viewCatalog' => 'Angalia Orodha',
                'freeDelivery' => 'Usafirishaji Bure',
                'freeDeliveryDesc' => 'Kwa oda zaidi ya TSh 100,000',
                'securePayment' => 'Malipo Salama',
                'securePaymentDesc' => '100% malipo salama',
                'easyReturns' => 'Kurudisha Rahisi',
                'easyReturnsDesc' => 'Sera ya kurejesha siku 7',
                'support247' => 'Msaada 24/7',
                'support247Desc' => 'Huduma ya wateja',
                'shopByCategory' => 'Nunua kwa Kategoria',
                'browseCategories' => 'Vinua aina mbalimbali za elektroniki',
                'productsCount' => 'bidhaa {count}',
                'categoriesComingSoon' => 'Kategoria zinakuja hivi karibuni',
                'newArrivals' => 'Mpya',
                'newArrivalsDesc' => 'Angalia bidhaa zetu mpya',
                'noProductsYet' => 'Hakuna bidhaa bado. Angalia tena hivi karibuni!',
                'viewMoreProducts' => 'Angalia Bidhaa Zaidi',
                'hotDeals' => 'Ofa za Moto',
                'hotDealsTitle' => 'Punguzo la Hadi 30% kwa Bidhaa',
                'hotDealsDesc' => 'Ofa ya muda kwa elektroniki maarufu',
                'shopTheSale' => 'Nunua Ofa',
                'hotSelling' => 'Bidhaa Zinazouzwa Sana',
                'hotSellingDesc' => 'Bidhaa maarufu zinazopendwa na wateja wetu',
                'viewAllProducts' => 'Angalia Bidhaa Zote',
                'createAccount' => 'Unda Akaunti',
                'createAccountDesc' => 'Jiandikishe kwa ofa na ufuatiliaji wa oda',
                'register' => 'Jiandikishe',
                'mobileMoney' => 'Pesa za Simu',
                'mobileMoneyDesc' => 'Lipa kwa M-Pesa, Tigo Pesa, au Airtel Money',
                'fastDeliveryTitle' => 'Usafirishaji wa Haraka',
                'fastDeliveryDesc' => 'Usafirishaji wa siku moja Dar es Salaam',
                'dirBadge' => 'Maduka Yetu',
                'dirTitle' => 'Nunua kutoka maduka ya washirika wetu',
                'dirSubtitle' => 'Vinjari vifaa vya elektroniki kutoka maduka yetu yote mahali pamoja. Chagua duka kuanza ununuzi.',
                'dirProductsCount' => 'bidhaa {count}',
                'dirVisitStore' => 'Tembelea Duka',
                'dirEmpty' => 'Hakuna maduka yanayopatikana kwa sasa. Tafadhali rudi baadaye.',
                'dirNew' => 'Mpya',
                'dirNewArrivals' => 'Bidhaa mpya {count} zimefika',
            ],
        ];

        DB::table('settings')->updateOrInsert(
            ['key' => 'home_content'],
            [
                'value' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'home_content')->delete();
    }
};
