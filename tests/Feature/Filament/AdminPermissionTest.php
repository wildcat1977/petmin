<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 受保護管理員名單改由 config/env 驅動（原 User::PROTECTED_ADMIN_EMAILS 常數已移除）
        config(['auth.protected_admin_emails' => [
            'wildcat.young@gmail.com',
            'genehong@gmail.com',
        ]]);
    }

    public function test_protected_admin_emails_reads_from_config(): void
    {
        $this->assertContains('wildcat.young@gmail.com', User::protectedAdminEmails());
        $this->assertContains('genehong@gmail.com', User::protectedAdminEmails());
    }

    public function test_regular_admin_is_not_protected(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'other@example.com']);

        $this->assertNotContains($admin->email, User::protectedAdminEmails());
    }

    public function test_is_admin_can_be_set_to_true(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $user->update(['is_admin' => true]);

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_is_admin_can_be_set_to_false_for_regular_admin(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'other@example.com']);

        $this->assertFalse(in_array($admin->email, User::protectedAdminEmails()));

        $admin->update(['is_admin' => false]);

        $this->assertFalse($admin->fresh()->is_admin);
    }

    public function test_protected_emails_are_in_protected_admin_emails_list(): void
    {
        foreach (User::protectedAdminEmails() as $email) {
            $user = User::factory()->admin()->create(['email' => $email]);

            $this->assertTrue(in_array($user->email, User::protectedAdminEmails()));
        }
    }
}
