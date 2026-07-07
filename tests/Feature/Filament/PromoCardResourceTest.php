<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PromoCards\Pages\CreatePromoCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 圖卡後台管理（spec §11.6）：title 超過 DB varchar(100) 上限須為
 * 表單驗證錯誤，而非落庫時的 QueryException 500。
 */
class PromoCardResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_title_longer_than_100_chars_is_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePromoCard::class)
            ->fillForm([
                'title' => str_repeat('a', 101),
                'group' => 'shen',
            ])
            ->call('create')
            ->assertHasFormErrors(['title']);
    }
}
