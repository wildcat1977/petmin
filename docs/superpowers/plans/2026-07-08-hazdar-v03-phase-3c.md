# Hazdar v0.3 Phase 3c — 圖卡輪播 banner + 移除 /cards 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 `/cards` 獨立圖卡頁改成首頁「交通與清運資訊」卡下方的自動輪播單一 banner（有啟用圖卡才顯示、點擊看大圖），並移除 `/cards` 頁（route 302 → `/`）。後台 Filament `PromoCard` 管理保留。

**Architecture:** 圖卡資料併入 `eoc-dashboard` 既有的 `sharedCardData()` 10s 快取（純陣列 `{title, image_url}`，遵守禁存 Eloquent 物件規則）。新增 `card-promo` 局部視圖，用 inline Alpine 做輪播 + lightbox，於 `eoc-dashboard.blade.php` 桌機右欄與行動版抽屜「交通清運卡」之後 include。`routes/web.php` 的 `/cards` closure 改 302 轉向、刪 `pages/cards.blade.php`，並改寫既有 `PromoCardsPageTest`。

**Tech Stack:** Livewire 4 SFC、Alpine.js（inline x-data）、Tailwind、Laravel Storage public disk、PHPUnit + Livewire test。

## Global Constraints（spec §12.5，逐條照抄）

- banner 放在「交通與清運資訊」卡下方；自動輪播（~5s）啟用中 `PromoCard`；點擊開 lightbox；**無啟用圖卡則不渲染**。
- 圖卡資料併入 `eoc-dashboard` `sharedCardData()` 快取，型別為 `list<array{title:string, image_url:string}>`——**只存純陣列**，不得快取 Eloquent 物件/Collection（見 `ReportCategory` 教訓）。
- 移除 `/cards` 獨立頁：刪 `pages/cards.blade.php`、route 改 `Route::redirect('/cards','/',302)`；Filament `PromoCard` 後台**保留**。
- 圖卡取用 `Storage::disk('public')->url($card->image_path)`（與舊 `/cards` 頁一致）。
- 禁止改動 `PromoCard` model / migration / Filament resource / factory。

## 現況參考（實作前必讀）

- `resources/views/livewire/eoc-dashboard.blade.php:1-11` — `use` 區（需加 `use App\Models\PromoCard;`）。
- `eoc-dashboard.blade.php:46-55` — `sharedCardData()`（回傳 stats/alerts/infoLinks/openShelterCount/rainTrend；本 Phase 加 `promoCards`）。
- `eoc-dashboard.blade.php:207-215` — 桌機右欄（`stats` 卡 + `info` 卡）。
- `eoc-dashboard.blade.php:217-235` — 行動版底部抽屜（同五卡；本 Phase 於 `info` 卡後加 promo；**注意** Phase 3d 會重整此區塊）。
- `app/Models/PromoCard.php` — `image_path`、`is_active`、`sort_order`、`group`；`GROUP_LABELS`。
- `resources/views/pages/cards.blade.php` — 舊圖卡頁（本 Phase 刪除），其 lightbox `Storage::disk('public')->url($card->image_path)` 為 URL 取法參考。
- `routes/web.php:47-56` — `/cards` closure（本 Phase 改 302）；`routes/web.php:8` `use App\Models\PromoCard;`（改後不再用，移除）。
- `tests/Feature/PromoCardsPageTest.php` — 舊 `/cards` 頁測試（本 Phase 改寫）。

---

### Task 1: 圖卡輪播 banner（資料 + 視圖）

**Files:**
- Modify: `resources/views/livewire/eoc-dashboard.blade.php`（`use` 區、`sharedCardData()`、加 `getPromoCardsProperty` + `computePromoCards`、桌機右欄與行動抽屜 include）
- Create: `resources/views/livewire/partials/card-promo.blade.php`
- Test: `tests/Feature/PromoBannerTest.php`（新建）

**Interfaces:**
- Produces: `getPromoCardsProperty(): array`（`list<array{title, image_url}>`）；`card-promo` 局部視圖讀 `$this->promoCards`；wrapper 帶 `data-promo-banner` 供測試偵測。

- [ ] **Step 1: 寫失敗測試**

建立 `tests/Feature/PromoBannerTest.php`：

```php
<?php

namespace Tests\Feature;

use App\Models\PromoCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 圖卡輪播 banner（spec §12.5）：啟用圖卡於首頁交通清運卡下方輪播；
 * 下架圖卡不外洩；無啟用圖卡則不渲染 banner。
 */
class PromoBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_promo_card_shows_and_inactive_hidden(): void
    {
        PromoCard::create(['title' => '防災三步驟', 'image_path' => 'cards/a.png', 'group' => 'shen', 'is_active' => true]);
        PromoCard::create(['title' => '下架卡', 'image_path' => 'cards/c.png', 'group' => 'shen', 'is_active' => false]);

        Livewire::test('eoc-dashboard')
            ->assertSee('data-promo-banner', false)
            ->assertSee('cards/a.png', false)
            ->assertDontSee('cards/c.png', false);
    }

    public function test_no_banner_when_no_active_cards(): void
    {
        Livewire::test('eoc-dashboard')->assertDontSee('data-promo-banner', false);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=PromoBannerTest`
Expected: FAIL（尚無 banner 與 `promoCards`）。

- [ ] **Step 3: eoc-dashboard 加 promoCards 資料**

`resources/views/livewire/eoc-dashboard.blade.php` `use` 區（約 3-10 行）加：
```php
use App\Models\PromoCard;
use Illuminate\Support\Facades\Storage;
```

`sharedCardData()`（46-55 行）在陣列中加一行 `promoCards`：
```php
        return Cache::remember('hazdar:eoc:cards', 10, fn () => [
            'stats' => $this->computeStats(),
            'alerts' => $this->computeAlerts(),
            'infoLinks' => $this->computeInfoLinks(),
            'openShelterCount' => $this->computeOpenShelterCount(),
            'rainTrend' => $this->computeRainTrend(),
            'promoCards' => $this->computePromoCards(),
        ]);
```

在 `computeOpenShelterCount()`（約 164-167 行）之後加：
```php
    /**
     * 啟用中圖卡（spec §12.5）：只存純陣列（title/image_url），避免快取 Eloquent 物件。
     *
     * @return list<array{title: string, image_url: string}>
     */
    private function computePromoCards(): array
    {
        return PromoCard::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['title', 'image_path'])
            ->map(fn (PromoCard $c) => [
                'title' => $c->title,
                'image_url' => Storage::disk('public')->url($c->image_path),
            ])
            ->all();
    }

    public function getPromoCardsProperty(): array
    {
        return $this->sharedCardData()['promoCards'];
    }
```

- [ ] **Step 4: 建 `card-promo` 局部視圖**

建立 `resources/views/livewire/partials/card-promo.blade.php`：

```blade
{{-- 圖卡輪播 banner（spec §12.5）：自動輪播啟用中圖卡；點擊看大圖。無圖卡不渲染。 --}}
@if (count($this->promoCards))
<div class="pointer-events-auto" data-promo-banner
     x-data="{ idx: 0, lightbox: null, count: {{ count($this->promoCards) }},
               init() { if (this.count > 1) { setInterval(() => { this.idx = (this.idx + 1) % this.count }, 5000) } } }">
    <div class="relative overflow-hidden rounded-xl border border-white/10 bg-[#003a30]/95">
        @foreach ($this->promoCards as $i => $card)
            <button type="button" x-show="idx === {{ $i }}" x-cloak x-transition.opacity
                    @click="lightbox = @js($card['image_url'])"
                    class="block w-full">
                <img src="{{ $card['image_url'] }}" alt="{{ $card['title'] }}" loading="lazy"
                     class="aspect-[3/1] w-full object-cover">
            </button>
        @endforeach
        @if (count($this->promoCards) > 1)
            <div class="absolute inset-x-0 bottom-1 flex justify-center gap-1">
                @foreach ($this->promoCards as $i => $card)
                    <span class="h-1.5 w-1.5 rounded-full transition"
                          :class="idx === {{ $i }} ? 'bg-cyan-300' : 'bg-white/30'"></span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- lightbox --}}
    <div x-show="lightbox" x-cloak @click="lightbox = null"
         class="fixed inset-0 z-[600] flex items-center justify-center bg-black/90 p-4">
        <img :src="lightbox" @click.stop class="max-h-[80dvh] max-w-full rounded-xl object-contain">
    </div>
</div>
@endif
```

- [ ] **Step 5: 於桌機右欄與行動抽屜 include**

`eoc-dashboard.blade.php` 桌機右欄，把 `info` 卡（約 212-214 行）之後補一行：
```blade
        <x-dashboard-card card-key="info" :title="__('交通與清運資訊彙整')">
            @include('livewire.partials.card-info')
        </x-dashboard-card>
        @include('livewire.partials.card-promo')
```
行動抽屜同樣在 `info` 卡（約 232-234 行）之後補：
```blade
        <x-dashboard-card card-key="info" :title="__('交通與清運資訊彙整')">
            @include('livewire.partials.card-info')
        </x-dashboard-card>
        @include('livewire.partials.card-promo')
```

- [ ] **Step 6: build + 測試 + 瀏覽器驗收**

```bash
npm run build
php artisan test --compact --filter=PromoBannerTest
```
Expected: PASS。chrome-devtools：上傳/建立 ≥2 張啟用圖卡後開 `/`，交通清運卡下方見輪播（~5s 換頁、圓點指示），點擊開大圖；無啟用圖卡時 banner 不出現。

- [ ] **Step 7: pint + Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/eoc-dashboard.blade.php resources/views/livewire/partials/card-promo.blade.php tests/Feature/PromoBannerTest.php
git commit -m "feat: v0.3 圖卡輪播 banner（交通清運卡下方，自動輪播+lightbox，spec §12.5）"
```

---

### Task 2: 移除 /cards 頁 + 302 轉向 + 改寫舊測試

**Files:**
- Modify: `routes/web.php:8`（移除 `use App\Models\PromoCard;`）、`routes/web.php:47-56`（closure → redirect）
- Delete: `resources/views/pages/cards.blade.php`
- Rewrite: `tests/Feature/PromoCardsPageTest.php`

**Interfaces:**
- Consumes: 無。
- Produces: `GET /cards` → 302 `/`。

- [ ] **Step 1: 改寫測試**

把 `tests/Feature/PromoCardsPageTest.php` 內容整個換成：

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 圖卡專區獨立頁已退場（spec §12.5）：/cards 302 轉向首頁；
 * 圖卡改由首頁輪播 banner 呈現（見 PromoBannerTest）。
 */
class PromoCardsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_url_redirects_to_home(): void
    {
        $this->get('/cards')->assertRedirect('/');
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=PromoCardsPageTest`
Expected: FAIL（目前 `/cards` 回 200 頁面，非 302）。

- [ ] **Step 3: route 改 302 + 移除 unused import**

`routes/web.php:47-56` 整段 `/cards` closure 換成：
```php
// 圖卡專區改為首頁輪播 banner（spec §12.5）；舊網址 302 轉向
Route::redirect('/cards', '/', 302)->name('cards');
```
移除 `routes/web.php:8` 的 `use App\Models\PromoCard;`（改後不再使用；pint 會抓 unused import）。

- [ ] **Step 4: 刪除舊頁**

```bash
git rm resources/views/pages/cards.blade.php
```

- [ ] **Step 5: 跑測試確認通過**

Run: `php artisan test --compact --filter=PromoCardsPageTest`
Expected: PASS。

- [ ] **Step 6: 回歸 Phase 3a 遺留測試**

Phase 3a Task 2 移除了 header 圖卡連結，`PromoCardsPageTest::test_home_page_links_to_cards`（舊）已隨本 Task 整檔改寫刪除，該遺留失敗即消除。跑：
```bash
php artisan test --compact
```
Expected: 全套綠（含 HomeChromeTest / MapMarkerTest / PromoBannerTest / PromoCardsPageTest）。

- [ ] **Step 7: pint + Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php tests/Feature/PromoCardsPageTest.php
git commit -m "refactor: v0.3 移除 /cards 獨立頁改 302 轉向，圖卡改首頁 banner（spec §12.5）"
```

---

## 驗證（Phase 3c 收尾）

- `php artisan test --compact` → 全綠。
- `grep -rn "route('cards')" resources tests` → 除 `web.php` 的 redirect 定義外，前台無殘留連結。
- chrome-devtools：`/cards` 轉向 `/`；首頁 banner 行為正確。

## Self-Review 註記

- **Spec 覆蓋**：§12.5 banner（Task 1）+ 移除 /cards（Task 2）全覆蓋。
- **快取安全**：`promoCards` 存純陣列（title/image_url 皆字串），未違反禁存 Eloquent 物件規則。
- **跨 Phase**：本 Phase 於行動抽屜也 include `card-promo`；Phase 3d 重整行動版時會把它移入右側面板——屆時桌機 include 不動。
- **型別一致**：`getPromoCardsProperty(): array` 元素 `{title, image_url}`；`card-promo` 讀同鍵名。
