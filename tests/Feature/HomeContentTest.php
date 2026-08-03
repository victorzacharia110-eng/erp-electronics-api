<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_content_returns_defaults(): void
    {
        $this->getJson('/api/settings/home-content')
            ->assertOk()
            ->assertJsonPath('en.heroTitle', 'Quality Electronics at')
            ->assertJsonPath('sw.heroTitle', 'Elektroniki Bora kwa')
            ->assertJsonPath('en.hotDealsTitle', 'Up to 30% Off on Selected Items');
    }

    public function test_superadmin_can_update_home_content(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);
        $this->actingAs($superadmin, 'sanctum');

        $this->putJson('/api/superadmin/settings/home-content', [
            'en' => ['heroTitle' => 'Custom EN Title', 'heroBadge' => 'Fresh', 'unknownKey' => 'ignored'],
            'sw' => ['heroTitle' => 'Kichwa cha Swahili', 'heroBadge' => 'Mpya'],
        ])->assertOk()
            ->assertJsonPath('content.en.heroTitle', 'Custom EN Title')
            ->assertJsonPath('content.sw.heroTitle', 'Kichwa cha Swahili');

        $this->getJson('/api/settings/home-content')
            ->assertOk()
            ->assertJsonPath('en.heroTitle', 'Custom EN Title')
            ->assertJsonPath('sw.heroTitle', 'Kichwa cha Swahili')
            ->assertJsonPath('en.heroBadge', 'Fresh')
            ->assertJsonMissingPath('en.unknownKey');
    }

    public function test_non_superadmin_cannot_update_home_content(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->putJson('/api/superadmin/settings/home-content', [
            'en' => ['heroTitle' => 'Nope'],
            'sw' => ['heroTitle' => 'Hapana'],
        ])->assertForbidden();
    }

    public function test_unauthenticated_cannot_update_home_content(): void
    {
        $this->putJson('/api/superadmin/settings/home-content', [
            'en' => ['heroTitle' => 'Nope'],
            'sw' => ['heroTitle' => 'Hapana'],
        ])->assertUnauthorized();
    }
}
