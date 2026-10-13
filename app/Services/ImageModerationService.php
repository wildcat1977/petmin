<?php

namespace App\Services;

use App\Models\ReportCategory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

class ImageModerationService
{
    private Client $client;

    private string $model;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout' => config('services.claude.timeout', 15),
            'headers' => [
                'x-api-key' => config('services.claude.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
        ]);

        $this->model = config('services.claude.model', 'claude-haiku-5-5');
    }

    /**
     * 審核圖片路徑（本地暫存路徑或絕對路徑）。
     * 先用 Intervention Image 縮到長邊 ≤ 1024px 再送審，節省 Token 與頻寬。
     *
     * 職責：照片內容適切性 + 是否吻合所選狀況分類。
     * 不吻合分類不自動退件，由呼叫端標記人工複核。
     *
     * @param  string  $category  狀況分類 slug（report_categories）
     * @return array{is_valid: bool, matches_category: bool, confidence: float, reason: string}
     */
    public function moderate(string $imagePath, string $category): array
    {
        try {
            $base64 = $this->prepareBase64($imagePath);

            $prompt = $this->prompt($category);

            $payload = [
                'model' => $this->model,
                'max_tokens' => 96,
                // Haiku 5.5 預設會先 thinking；96 token 會整個被 thinking 吃掉、沒有 text 區塊，
                // 結果靜默變成「通過」。這是單純的是非判斷，關掉 thinking（跟 Haiku 4.5 行為一致）。
                'thinking' => ['type' => 'disabled'],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'image/jpeg',
                                    'data' => $base64,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->client->post('/v1/messages', ['json' => $payload]);
            $body = json_decode($response->getBody()->getContents(), true);
            $text = trim(self::extractText($body));

            // Extract JSON even if model wraps it in markdown
            if (preg_match('/\{[^}]+\}/', $text, $m)) {
                $text = $m[0];
            }

            $result = json_decode($text, true);

            $usage = $body['usage'] ?? [];

            Log::channel('moderation')->info('moderation.result', [
                'model' => $body['model'] ?? $this->model,
                'is_valid' => (bool) ($result['is_valid'] ?? true),
                'matches_category' => (bool) ($result['matches_category'] ?? true),
                'confidence' => (float) ($result['confidence'] ?? 0.5),
                'raw_text' => $text,
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'total_tokens' => isset($usage['input_tokens'], $usage['output_tokens'])
                    ? $usage['input_tokens'] + $usage['output_tokens']
                    : null,
            ]);

            return [
                'is_valid' => (bool) ($result['is_valid'] ?? true),
                'matches_category' => (bool) ($result['matches_category'] ?? true),
                'confidence' => (float) ($result['confidence'] ?? 0.5),
                'reason' => 'claude',
            ];
        } catch (GuzzleException $e) {
            // API 失敗時預設放行，避免誤擋正常上傳
            Log::channel('moderation')->warning('moderation.api_error', [
                'model' => $this->model,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            Log::warning('ImageModerationService: API error', [
                'message' => $e->getMessage(),
            ]);

            return ['is_valid' => true, 'matches_category' => true, 'confidence' => 0.0, 'reason' => 'api_error'];
        } catch (\Throwable $e) {
            Log::channel('moderation')->warning('moderation.error', [
                'model' => $this->model,
                'message' => $e->getMessage(),
            ]);
            Log::warning('ImageModerationService: unexpected error', [
                'message' => $e->getMessage(),
            ]);

            return ['is_valid' => true, 'matches_category' => true, 'confidence' => 0.0, 'reason' => 'error'];
        }
    }

    /** @param  array<string, mixed>  $body */
    public static function extractText(array $body): string
    {
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }

        return '{}';
    }

    /**
     * 將圖片縮小至長邊 ≤ 1024px 後轉為 JPEG Base64。
     */
    private function prepareBase64(string $imagePath): string
    {
        $manager = new ImageManager(
            new Driver
        );

        $image = $manager->decode($imagePath);

        // Scale down if larger than 1024px on longest side
        $w = $image->width();
        $h = $image->height();
        if ($w > 1024 || $h > 1024) {
            if ($w >= $h) {
                $image->scale(width: 1024);
            } else {
                $image->scale(height: 1024);
            }
        }

        $jpeg = $image->encode(new JpegEncoder(quality: 70));

        return base64_encode($jpeg->toString());
    }

    private function prompt(string $category): string
    {
        $label = ReportCategory::labels()[$category] ?? $category;

        return <<<PROMPT
<task>
你是「Petmin 垃圾桶回報」的 AI 審核員。市民回報的狀況分類為「{$label}」。請判斷：
1. 這張圖片是否適合公開於地圖（is_valid）
2. 圖片內容是否與所選分類吻合（matches_category）
</task>

<category_hints>
- 這裡需要垃圾桶：街角、公園、遛狗路線上沒有垃圾桶的街景，或地上堆著無處可丟的垃圾／狗便袋
- 桶滿了：行人專用清潔箱（綠色或灰色不鏽鋼箱）滿溢、垃圾堆在桶外
- 桶消失了：原本桶的位置只剩底座、鐵架、地面痕跡，或空蕩的街景
- 桶還在：完整、可使用的行人專用清潔箱
- 狗便袋箱沒袋了：狗便清潔袋箱（公園裡的塑膠袋取用箱）空了
- 家戶垃圾亂丟：清潔箱旁堆著家用垃圾袋、廚餘、紙箱
- 毒餌／危險物：路邊可疑的餌、藥劑、碎玻璃等對寵物危險的東西
</category_hints>

<accept_if>
以下情況請接受（is_valid=true）：
- 戶外街景、公園、河濱、騎樓的現場照片，即使模糊、逆光、光線昏暗
- 垃圾桶或狀況主體只占畫面角落或一小部分
- 畫面有狗或牽繩（遛狗族回報很常見）
</accept_if>

<reject_if>
以下情況才拒絕（is_valid=false）：
- 清晰可辨的人臉
- 不雅或色情內容
- 明確與街景／垃圾桶完全無關的照片（如：自拍、食物特寫、螢幕截圖梗圖）
</reject_if>

<guidance>
審核標準應寬鬆，傾向接受。寧可接受模糊相關，也不誤擋真實回報。
matches_category 僅判斷內容與「{$label}」是否吻合；不吻合時仍可 is_valid=true。
confidence 代表你對 is_valid 判斷的把握度（0.0–1.0）。若場景模糊難判，請給低 confidence。
</guidance>

<output>
嚴格只輸出 JSON，不得包含任何其他文字：{"is_valid":true,"matches_category":true,"confidence":0.9}
</output>
PROMPT;
    }
}
