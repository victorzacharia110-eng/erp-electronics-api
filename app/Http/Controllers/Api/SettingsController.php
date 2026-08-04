<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\OwnerProfile;
use App\Models\Setting;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function payment(Request $request): JsonResponse
    {
        $ownerId = null;

        if ($slug = $request->query('business')) {
            $business = Tenant::bySlug($slug);
            $ownerId = $business?->owner_id;
        }

        if (!$ownerId) {
            $ownerId = Business::where('is_active', true)->orderBy('id')->value('owner_id');
        }

        $scopedKey = $ownerId ? "clickpesa_enabled:{$ownerId}" : null;

        $clickpesaEnabled = $scopedKey
            ? Setting::where('key', $scopedKey)->first()
            : null;

        if (!$clickpesaEnabled) {
            $clickpesaEnabled = Setting::where('key', 'clickpesa_enabled')->first();
        }

        return response()->json([
            'clickpesa_enabled' => $clickpesaEnabled ? $clickpesaEnabled->getTypedValue() : false,
        ]);
    }

    public function updatePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clickpesa_enabled' => 'required|boolean',
        ]);

        $ownerId = $request->ownerId() ?? $request->user()->id;

        Setting::updateOrCreate(
            ['key' => "clickpesa_enabled:{$ownerId}"],
            [
                'value' => $validated['clickpesa_enabled'] ? 'true' : 'false',
                'type' => 'boolean',
            ]
        );

        return response()->json([
            'message' => 'Payment settings updated',
            'clickpesa_enabled' => $validated['clickpesa_enabled'],
        ]);
    }

    public function homeContent(): JsonResponse
    {
        return response()->json($this->homeContentData());
    }

    public function updateHomeContent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'en' => 'required|array',
            'sw' => 'required|array',
        ]);

        $allowed = array_keys($this->homeContentDefaults()['en']);

        $sanitize = function (array $values) use ($allowed): array {
            $clean = [];
            foreach ($allowed as $key) {
                $clean[$key] = isset($values[$key]) && is_scalar($values[$key])
                    ? (string) $values[$key]
                    : '';
            }
            return $clean;
        };

        Setting::updateOrCreate(
            ['key' => 'home_content'],
            [
                'value' => json_encode([
                    'en' => $sanitize($validated['en']),
                    'sw' => $sanitize($validated['sw']),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
            ]
        );

        return response()->json([
            'message' => 'Home content updated',
            'content' => $this->homeContentData(),
        ]);
    }

    private function homeContentData(): array
    {
        $defaults = $this->homeContentDefaults();
        $saved = Setting::where('key', 'home_content')->first();
        $stored = $saved ? $saved->getTypedValue() : [];
        $stored = is_array($stored) ? $stored : [];

        return [
            'en' => array_replace($defaults['en'], $stored['en'] ?? []),
            'sw' => array_replace($defaults['sw'], $stored['sw'] ?? []),
        ];
    }

    private function homeContentDefaults(): array
    {
        return [
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
    }

    public function branding(Request $request): JsonResponse
    {
        $profile = null;

        if ($business = \App\Support\Tenant::bySlug($request->query('business'))) {
            $profile = OwnerProfile::where('user_id', $business->owner_id)->with('user')->first();
        }

        if (!$profile) {
            $profile = OwnerProfile::where('is_active', true)->with('user')->first();
        }

        if (!$profile) {
            return response()->json([
                'store_name' => 'ElectroShop',
                'tagline' => 'Your trusted electronics store',
                'logo_path' => null,
                'color' => '#e74c3c',
                'color_secondary' => '#2c3e50',
            ]);
        }

        return response()->json([
            'store_name' => $profile->brand_store_name || 'ElectroShop',
            'tagline' => $profile->brand_tagline || 'Your trusted electronics store',
            'logo_path' => $profile->brand_logo_path ? '/branding/' . $profile->brand_logo_path : null,
            'color' => $profile->brand_color || '#e74c3c',
            'color_secondary' => $profile->brand_color_secondary || '#2c3e50',
        ]);
    }
}
