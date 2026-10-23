<?php

namespace Tests\Feature;

use App\Services\BinNameModerationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 桶名會公開掛在市府議題的地圖上：髒話、廣告、聯絡方式要在送 AI 前就擋掉（不花錢、當場回饋），
 * 變形寫法（加空格、符號、簡體、全形）也要擋；AI 失敗時寧可不公開（fail-closed）。
 */
class BinNameModerationServiceTest extends TestCase
{
    private BinNameModerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BinNameModerationService::class);
    }

    public function test_clean_name_passes_precheck(): void
    {
        $this->assertNull($this->service->precheck('柴柴的秘密基地'));
        $this->assertNull($this->service->precheck('大安森林 No.1'));
    }

    public function test_length_limits(): void
    {
        $this->assertNotNull($this->service->precheck('桶'));
        $this->assertNotNull($this->service->precheck('   '));
        $this->assertNotNull($this->service->precheck(str_repeat('汪', 13)));
        $this->assertNull($this->service->precheck(str_repeat('汪', 12)));
    }

    public function test_urls_and_phone_numbers_are_rejected(): void
    {
        $this->assertNotNull($this->service->precheck('www.spam.tw'));
        $this->assertNotNull($this->service->precheck('買狗糧 shop.com'));
        $this->assertNotNull($this->service->precheck('打 0912-345-678'));
        $this->assertNull($this->service->precheck('101 旁的桶'), '一般數字不是電話');
    }

    public function test_blocklist_catches_disguised_variants(): void
    {
        foreach (['幹你娘', '幹 你 娘', '干你娘', '傻逼桶', '傻.逼', 'ＦＵＣＫ', 'f u c k'] as $name) {
            $this->assertNotNull($this->service->precheck($name), "應擋下：{$name}");
        }
    }

    public function test_display_name_collapses_whitespace(): void
    {
        $this->assertSame('柴柴 基地', $this->service->clean("  柴柴 \n  基地 "));
    }

    public function test_ai_review_sends_structured_request_and_reads_verdict(): void
    {
        config(['services.claude.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '{"allowed":false,"reason":"嘲諷政治人物"}']],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 12],
        ])]);

        $this->assertSame(['allowed' => false, 'reason' => '嘲諷政治人物'], $this->service->review('某某某下台桶'));

        Http::assertSent(function (Request $request) {
            // Haiku 5.5 預設會 thinking，小 max_tokens 會被吃光而沒有 text（見 ImageModerationService）
            return $request['thinking'] === ['type' => 'disabled']
                && $request['output_config']['format']['type'] === 'json_schema'
                && str_contains($request['messages'][0]['content'], '某某某下台桶')
                && $request->hasHeader('x-api-key', 'test-key');
        });
    }

    public function test_ai_failure_or_refusal_returns_null_so_name_stays_pending(): void
    {
        config(['services.claude.api_key' => 'test-key']);

        Http::fakeSequence('api.anthropic.com/*')
            ->push(['error' => ['type' => 'overloaded_error']], 529)
            ->push(['stop_reason' => 'refusal', 'content' => []])
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '不是 JSON']]]);

        $this->assertNull($this->service->review('柴柴的秘密基地'));
        $this->assertNull($this->service->review('柴柴的秘密基地'));
        $this->assertNull($this->service->review('柴柴的秘密基地'));
    }

    public function test_missing_api_key_returns_null_without_calling(): void
    {
        config(['services.claude.api_key' => null]);
        Http::fake();

        $this->assertNull($this->service->review('柴柴的秘密基地'));
        Http::assertNothingSent();
    }
}
