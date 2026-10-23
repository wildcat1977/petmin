<?php

namespace Tests\Feature;

use App\Jobs\ModerateBinName;
use App\Models\BinName;
use App\Models\TrashBin;
use App\Models\User;
use App\Services\BinNameModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** 桶名 AI 審核：通過才上地圖；AI 沒給判斷就停在待審（fail-closed），不能默默公開。 */
class ModerateBinNameJobTest extends TestCase
{
    use RefreshDatabase;

    private function pendingName(): BinName
    {
        return BinName::create([
            'trash_bin_id' => TrashBin::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'name' => '柴柴的秘密基地',
            'status' => BinName::STATUS_PENDING,
        ]);
    }

    private function runWith(?array $verdict, BinName $name): BinName
    {
        $mock = Mockery::mock(BinNameModerationService::class);
        $mock->shouldReceive('review')->once()->with('柴柴的秘密基地')->andReturn($verdict);
        $this->app->instance(BinNameModerationService::class, $mock);

        (new ModerateBinName($name->id))->handle($mock);

        return $name->fresh();
    }

    public function test_allowed_name_goes_live(): void
    {
        $name = $this->runWith(['allowed' => true, 'reason' => '可愛'], $this->pendingName());

        $this->assertSame(BinName::STATUS_APPROVED, $name->status);
        $this->assertNotNull($name->reviewed_at);
        $this->assertSame($name->id, $name->trashBin->currentName->id);
    }

    public function test_disallowed_name_is_rejected_with_ai_reason(): void
    {
        $name = $this->runWith(['allowed' => false, 'reason' => '提到政治人物'], $this->pendingName());

        $this->assertSame(BinName::STATUS_REJECTED, $name->status);
        $this->assertSame('ai', $name->rejection_reason);
        $this->assertSame('提到政治人物', $name->moderation_note);
        $this->assertNull($name->trashBin->currentName);
    }

    public function test_no_verdict_leaves_name_pending_for_admin(): void
    {
        $name = $this->runWith(null, $this->pendingName());

        $this->assertSame(BinName::STATUS_PENDING, $name->status);
        $this->assertNull($name->trashBin->currentName);
    }

    public function test_already_reviewed_name_is_not_touched(): void
    {
        // 管理員先手動處理了，佇列晚到不能蓋掉
        $name = $this->pendingName();
        $name->update(['status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'admin']);

        $mock = Mockery::mock(BinNameModerationService::class);
        $mock->shouldNotReceive('review');
        (new ModerateBinName($name->id))->handle($mock);

        $this->assertSame('admin', $name->fresh()->rejection_reason);
    }
}
