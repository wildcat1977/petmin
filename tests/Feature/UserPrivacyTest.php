<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_private_sets_is_private_true(): void
    {
        $user = User::factory()->create(['is_private' => false]);

        Livewire::actingAs($user)
            ->test('user-profile')
            ->call('togglePrivate');

        $this->assertTrue($user->fresh()->is_private);
    }

    public function test_toggle_private_turns_off_again(): void
    {
        $user = User::factory()->create(['is_private' => true]);

        Livewire::actingAs($user)
            ->test('user-profile')
            ->call('togglePrivate');

        $this->assertFalse($user->fresh()->is_private);
    }
}
