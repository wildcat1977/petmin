<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EditUser::beforeSave() 的兩道守衛：
 *  1. 受保護帳號的管理員權限永遠不得被撤銷（halt + danger 通知）。
 *  2. 任何 is_admin 的變更都必須先勾選「確認變更管理員權限」（halt + warning 通知）。
 *
 * 守衛的價值在於「擋下儲存」，所以每個案例除了斷言通知，也一定回頭確認資料庫沒被改動；
 * 只斷言通知的話，把 halt() 拿掉測試仍會通過。
 */
class EditUserAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        // 受保護名單釘死成固定值，才不會因為各機器 .env 的 PROTECTED_ADMIN_EMAILS 不同而時綠時紅。
        config(['auth.protected_admin_emails' => ['protected@example.com']]);

        $this->actor = User::factory()->admin()->create();
    }

    /** 守衛 1：受保護帳號被撤銷 admin 時，儲存必須被擋下。 */
    public function test_protected_account_cannot_have_admin_revoked(): void
    {
        $protected = User::factory()->admin()->create(['email' => 'protected@example.com']);

        Livewire::actingAs($this->actor)
            ->test(EditUser::class, ['record' => $protected->getKey()])
            ->fillForm(['is_admin' => false, 'is_admin_confirm' => true])
            ->call('save')
            ->assertNotified('無法撤銷管理員權限');

        $this->assertTrue($protected->refresh()->is_admin, '受保護帳號的 admin 權限不應被撤銷');
    }

    /** 守衛 1 的範圍：不碰 is_admin 時，受保護帳號的其他欄位仍可正常編輯。 */
    public function test_protected_account_can_still_edit_other_fields(): void
    {
        $protected = User::factory()->admin()->create([
            'email' => 'protected@example.com',
            'name' => '原本的名字',
        ]);

        Livewire::actingAs($this->actor)
            ->test(EditUser::class, ['record' => $protected->getKey()])
            ->fillForm(['name' => '改過的名字'])
            ->call('save')
            ->assertHasNoFormErrors();

        $protected->refresh();
        $this->assertSame('改過的名字', $protected->name);
        $this->assertTrue($protected->is_admin, '沒有變更 is_admin 時不應觸發守衛');
    }

    /** 守衛 2：未勾選確認框就撤銷 admin，儲存必須被擋下。 */
    public function test_revoking_admin_without_confirmation_is_blocked(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'regular-admin@example.com']);

        Livewire::actingAs($this->actor)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertNotified('請確認管理員權限變更');

        $this->assertTrue($admin->refresh()->is_admin, '未勾選確認框時不應撤銷 admin');
    }

    /** 守衛 2 對「授予」同樣有效，不只擋撤銷。 */
    public function test_granting_admin_without_confirmation_is_blocked(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        Livewire::actingAs($this->actor)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['is_admin' => true])
            ->call('save')
            ->assertNotified('請確認管理員權限變更');

        $this->assertFalse($user->refresh()->is_admin, '未勾選確認框時不應授予 admin');
    }

    /** Happy path：一般管理員在勾選確認框後可以被降權。 */
    public function test_regular_admin_can_be_demoted_when_confirmed(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'regular-admin@example.com']);

        Livewire::actingAs($this->actor)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['is_admin' => false, 'is_admin_confirm' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($admin->refresh()->is_admin, '非受保護的管理員在確認後應可降權');
    }

    /** Happy path：一般使用者在勾選確認框後可以被升為管理員。 */
    public function test_regular_user_can_be_promoted_when_confirmed(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        Livewire::actingAs($this->actor)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['is_admin' => true, 'is_admin_confirm' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->refresh()->is_admin, '確認後應可授予 admin');
    }
}
