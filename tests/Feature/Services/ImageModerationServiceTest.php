<?php

namespace Tests\Feature\Services;

use App\Services\ImageModerationService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageModerationServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Claude 5 系列可能先回 thinking block；只讀 content[0] 會靜默拿到空字串、照樣計費 */
    public function test_text_is_taken_from_the_first_text_block_not_index_zero(): void
    {
        $body = ['content' => [
            ['type' => 'thinking', 'thinking' => '...'],
            ['type' => 'text', 'text' => '{"is_valid":false,"confidence":0.8}'],
        ]];

        $this->assertSame('{"is_valid":false,"confidence":0.8}', ImageModerationService::extractText($body));
    }

    /**
     * Haiku 5.5 預設會先 thinking：96 token 的上限會被 thinking 用完、整則回應沒有 text 區塊，
     * extractText 只能回 '{}'，審核結果靜默變成「通過」。實測（2026-10-09）確認，所以 payload 必須關掉 thinking。
     */
    public function test_request_disables_thinking_so_the_small_token_budget_still_yields_a_verdict(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => '{"is_valid":false,"confidence":0.9}']]])),
        ]));
        $stack->push(Middleware::history($history));

        $service = new ImageModerationService;
        (new \ReflectionProperty($service, 'client'))->setValue($service, new Client(['handler' => $stack]));

        $image = tempnam(sys_get_temp_dir(), 'mod').'.jpg';
        imagejpeg(imagecreatetruecolor(8, 8), $image);

        try {
            $result = $service->moderate($image, 'flood');
        } finally {
            @unlink($image);
        }

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['type' => 'disabled'], $payload['thinking']);
        $this->assertFalse($result['is_valid']);
        $this->assertSame('claude', $result['reason']);
    }
}
