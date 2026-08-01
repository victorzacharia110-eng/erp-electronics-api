<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;

class Tenant
{
    /**
     * Resolve the active business for an owner (primary or co-owner).
     *
     * Preference: the X-Business-Id header, then the first business the user
     * owns, then the first business they co-own.
     */
    public static function activeBusiness(Request $request): ?Business
    {
        $user = $request->user();

        if (!$user || !$user->isOwner()) {
            return null;
        }

        $owned = Business::where('owner_id', $user->id)->orderBy('id')->get();
        $coOwned = $user->businesses()->orderBy('business_id')->get();

        if ($owned->isEmpty() && $coOwned->isEmpty()) {
            return null;
        }

        $candidates = $owned->concat($coOwned)->unique('id')->values();

        $requestedId = $request->header('X-Business-Id');
        if ($requestedId) {
            $match = $candidates->first(fn (Business $b) => (string) $b->id === (string) $requestedId);
            if ($match) {
                return $match;
            }
        }

        return $candidates->first();
    }

    /**
     * Resolve the tenant key (primary owner user id) for the active business.
     *
     * Falls back to the user's own id so existing single-owner installs keep
     * working before a business row exists.
     */
    public static function ownerId(Request $request): ?int
    {
        $business = self::activeBusiness($request);

        if ($business) {
            return $business->owner_id;
        }

        $user = $request->user();

        if ($user && $user->isOwner()) {
            return $user->id;
        }

        return null;
    }

    /**
     * Businesses the user can manage (owned + co-owned).
     */
    public static function forUser(User $user)
    {
        $owned = Business::where('owner_id', $user->id)->orderBy('id')->get();

        if ($user->isOwner()) {
            $coOwned = $user->businesses()->orderBy('business_id')->get();

            return $owned->concat($coOwned)->unique('id')->values();
        }

        return $owned;
    }

    /**
     * Resolve an active business from a storefront slug (public storefronts).
     */
    public static function bySlug(?string $slug): ?Business
    {
        if (!$slug) {
            return null;
        }

        return Business::where('slug', $slug)->where('is_active', true)->first();
    }
}
