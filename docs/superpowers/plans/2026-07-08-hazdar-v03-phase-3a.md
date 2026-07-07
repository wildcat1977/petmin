# Hazdar v0.3 Phase 3a — 配色 + 上下實色帶 + banner + footer/nav 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把首頁上下的半透明浮動 pill 改成 `#002923` 實色帶、左上換 banner 圖、放大跑馬燈、footer 改成分類新聞篇數並隱藏部分連結、把「我的回報 / 聯絡管理員」移到頂帶 nav。

**Architecture:** 純前端 chrome 調整。動 `resources/css/app.css`（配色 token）、`resources/views/layouts/app.blade.php`（body 底色 / theme-color）、`resources/views/components/dashboard-card.blade.php`（卡片 surface）、`resources/views/pages/radar.blade.php`（頂帶 / 底帶 / footer）、`routes/web.php`（首頁 route 補 `newsCounts`）。地圖點位、Livewire 卡片內容、後台皆不動。

**Tech Stack:** Laravel 13 blade、Tailwind 4（`bg-[#hex]` 任意值語法）、PHPUnit 12 feature test。

## Global Constraints（spec §12，逐條照抄）

- 全站底色 `#002923`；卡片 surface `#003a30`（微提亮做層次）。此為設計師指定值，**不得改動**。
- 頂帶、底帶、footer 為 `#002923` 實色帶，**非**半透明浮動 pill。
- banner 檔為既有 `public/Hazdar-Banner.png`（300×100 RGBA），顯示高 ~40px、寬自動。
- 跑馬燈字級 ≥13px（`text-[13px]`），資料源不變。
- footer 分類新聞篇數：用 `Announcement::published()` 依 `category` 分組計數，**只列有已發佈的分類**，整組連 `/news`。
- footer 隱藏（route 保留、不連）：回報清單 `reports`、透明度報告 `transparency`、統計地圖 `stats`；**移除** 圖卡專區連結。
- 頂帶 nav 新增：我的回報（`profile`，登入才顯示）、聯絡管理員（`open-contact-form` 事件鈕）。
- 禁止改動回報寫入路徑、外部資料落地、Livewire 卡片資料邏輯。
- 改 PHP 後跑 `vendor/bin/pint --dirty --format agent`。前端改動需 `npm run build` 才會反映（測試環境 blade 斷言不需 build）。
- 分類新聞篇數所需資料由**首頁 route closure** 傳入（`pages.radar` 目前是 `Route::view`，改為 `Route::get` 傳 `newsCounts`），不得在 blade 內直接下 DB query。

## 現況參考（實作前必讀）

- `routes/web.php:24` — `Route::view('/', 'pages.radar')->name('home');`（本計畫改成 closure）。
- `routes/web.php:7` — `use App\Models\Announcement;` 已存在。
- `Announcement::CATEGORY_LABELS`（`app/Models/Announcement.php:9-14`）：`school_closure`=停班停課、`home_safety`=居家災防、`gov_press`=市府與氣象署、`incident`=災情通報。
- `Announcement::scopePublished`（同檔 39-42）：`whereNotNull('published_at')->where('published_at','<=',now())`。
- `resources/views/pages/radar.blade.php:17-44` — 頂帶 header（HAZDAR pill + locale + 圖卡連結 + auth 區）。
- `resources/views/pages/radar.blade.php:66-105` — 底帶（公告 / my-report-status / 跑馬燈 pill / 立即回報鈕 / footer）。
- `resources/views/pages/radar.blade.php:76-81` — 跑馬燈容器（`#radar-ticker-text`，`text-[11px]`）。
- `resources/views/pages/radar.blade.php:90-104` — footer（含 reports/transparency/stats/cards/profile 連結 + 聯絡管理員鈕）。
- `resources/css/app.css:13` `--color-radar-bg: #0d1117`；`app.css:23-28` `#radar-map` 亮/暗底；`app.css:27` `#radar-map.map-theme-dark { background: #0d1117 }`。
- `resources/views/layouts/app.blade.php:11` `<meta name="theme-color" content="#0d1117">`；`app.blade.php:57` `<body class="bg-[#0d1117] ...">`。
- `resources/views/components/dashboard-card.blade.php:5` `bg-[#0f1522]/95`。

---

### Task 1: 全站配色 → `#002923`（卡片 `#003a30`）

**Files:**
- Modify: `resources/css/app.css:13`（`--color-radar-bg`）、`app.css:27`（暗色地圖底）
- Modify: `resources/views/layouts/app.blade.php:11`（theme-color）、`app.blade.php:57`（body class）
- Modify: `resources/views/components/dashboard-card.blade.php:5`（卡片 surface）
- Test: `tests/Feature/HomeChromeTest.php`（新建）

**Interfaces:**
- Produces: 首頁 HTML body 含 `bg-[#002923]`、不含 `bg-[#0d1117]`。後續 Task 2/3 在此底色上疊實色帶。

- [ ] **Step 1: 寫失敗測試**

建立 `tests/Feature/HomeChromeTest.php`：

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首頁 chrome（spec §12.1/12.2/12.4/12.6）：配色 #002923、banner、
 * 頂帶 nav、底帶跑馬燈與 footer 分類新聞篇數。
 */
class HomeChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_uses_new_background_color(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('#002923', false)
            ->assertDontSee('bg-[#0d1117]', false);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=test_layout_uses_new_background_color`
Expected: FAIL（目前 body 仍是 `bg-[#0d1117]`）。

- [ ] **Step 3: 改配色**

`resources/css/app.css:13`：
```css
    --color-radar-bg: #002923;
```
`resources/css/app.css:27`（`#radar-map.map-theme-dark`）：
```css
#radar-map.map-theme-dark {
    background: #002923;
}
```
`resources/views/layouts/app.blade.php:11`：
```blade
    <meta name="theme-color" content="#002923">
```
`resources/views/layouts/app.blade.php:57`：
```blade
<body class="bg-[#002923] text-slate-100 antialiased min-h-dvh">
```
`resources/views/components/dashboard-card.blade.php:5`（把 `bg-[#0f1522]/95` 改為 `#003a30`）：
```blade
<div class="pointer-events-auto overflow-hidden rounded-xl border border-white/10 bg-[#003a30]/95 backdrop-blur"
```

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact --filter=test_layout_uses_new_background_color`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add resources/css/app.css resources/views/layouts/app.blade.php resources/views/components/dashboard-card.blade.php tests/Feature/HomeChromeTest.php
git commit -m "feat: v0.3 全站配色改 #002923、卡片 #003a30（spec §12.1）"
```

---

### Task 2: 頂帶實色帶 + banner + nav（我的回報 / 聯絡管理員）

**Files:**
- Modify: `resources/views/pages/radar.blade.php:17-44`（header 區）
- Add to git: `public/Hazdar-Banner.png`（既有未追蹤檔）
- Test: `tests/Feature/HomeChromeTest.php`（延續 Task 1）

**Interfaces:**
- Consumes: Task 1 的 `#002923` 底。
- Produces: header 使用 `<img src="{{ asset('Hazdar-Banner.png') }}">`；含「我的回報」連結（`route('profile')`，auth）與「聯絡管理員」鈕（dispatch `open-contact-form`）。

- [ ] **Step 1: 寫失敗測試**（追加到 `HomeChromeTest`）

```php
    public function test_home_shows_banner_image(): void
    {
        $this->get('/')->assertOk()->assertSee('Hazdar-Banner.png', false);
    }

    public function test_top_nav_has_profile_and_contact_for_auth_user(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'zh-TW')
            ->get('/')
            ->assertOk()
            ->assertSee('我的回報')
            ->assertSee('聯絡管理員');
    }
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=HomeChromeTest`
Expected: 新增兩測試 FAIL（無 banner 圖、header 無「我的回報/聯絡管理員」）。

- [ ] **Step 3: 改 header 為實色帶 + banner + nav**

把 `resources/views/pages/radar.blade.php:17-44` 整段 `<header>` 換成：

```blade
        {{-- 頂帶：#002923 實色帶（spec §12.2/12.4）—— banner + nav --}}
        <header class="pointer-events-auto flex items-center justify-between gap-2 bg-[#002923] px-4 py-2 shadow-lg shadow-black/30">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-cyan-400"></span>
                <img src="{{ asset('Hazdar-Banner.png') }}" alt="Hazdar 全民災情回報地圖"
                     class="h-10 w-auto" width="300" height="100">
            </a>

            <nav class="flex items-center gap-2 text-xs">
                @include('partials.locale-switcher')
                <button type="button"
                        onclick="window.dispatchEvent(new CustomEvent('open-contact-form'))"
                        class="rounded-full bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20">
                    {{ __('聯絡管理員') }}
                </button>
                @auth
                    <a href="{{ route('profile') }}"
                       class="rounded-full bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20">
                        {{ __('我的回報') }}
                    </a>
                    <a href="{{ route('profile') }}" class="flex items-center gap-2 rounded-full bg-white/10 px-2 py-1.5 hover:bg-white/20">
                        @if (auth()->user()->avatar)
                            <img src="{{ auth()->user()->avatar }}" alt="" class="h-5 w-5 rounded-full object-cover">
                        @endif
                        <span class="hidden text-slate-200 sm:inline">{{ auth()->user()->name }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full bg-white/10 px-2 py-2 text-slate-400 hover:text-slate-200">{{ __('登出') }}</button>
                    </form>
                @endauth
            </nav>
        </header>
```

註：舊 header 的「🖼️ 圖卡專區」連結（原 26-29 行）在此移除（spec §12.5/12.6）。

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact --filter=HomeChromeTest`
Expected: PASS（三測試綠）。

- [ ] **Step 5: Commit（含 banner 資產）**

```bash
git add public/Hazdar-Banner.png resources/views/pages/radar.blade.php tests/Feature/HomeChromeTest.php
git commit -m "feat: v0.3 頂帶實色帶 + banner 圖 + nav 移入我的回報/聯絡管理員（spec §12.2/12.4/12.6）"
```

---

### Task 3: 底帶實色帶 + 放大跑馬燈 + footer 分類新聞篇數

**Files:**
- Modify: `routes/web.php:24`（首頁 route 改 closure 傳 `newsCounts`）
- Modify: `resources/views/pages/radar.blade.php:66-105`（底帶 + 跑馬燈 + footer）
- Test: `tests/Feature/HomeChromeTest.php`（延續）

**Interfaces:**
- Consumes: `Announcement::published()`、`Announcement::CATEGORY_LABELS`。
- Produces: `pages.radar` 收到 `$newsCounts`（`array<string,int>`，key=category slug、value=已發佈篇數，只含 >0 者）。

- [ ] **Step 1: 寫失敗測試**（追加到 `HomeChromeTest`）

```php
    public function test_footer_shows_published_news_counts_and_hides_removed_links(): void
    {
        // 注意：announcements.content 為 NOT NULL，create 必帶 content（見 NewsPageTest）
        \App\Models\Announcement::create(['title' => '停課通知', 'content' => 'x', 'category' => 'school_closure', 'is_active' => true, 'published_at' => now()->subHour()]);
        \App\Models\Announcement::create(['title' => '居家提醒', 'content' => 'x', 'category' => 'home_safety', 'is_active' => true, 'published_at' => now()->subHour()]);
        // 未設定 published_at：不列入 footer 計數
        \App\Models\Announcement::create(['title' => '草稿災情', 'content' => 'x', 'category' => 'incident', 'is_active' => true]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('停班停課')
            ->assertSee('居家災防')
            ->assertDontSee('災情通報')                 // 該分類無已發佈 → 不列
            ->assertSee(route('news'), false)
            ->assertDontSee(route('reports'), false)      // 回報清單隱藏
            ->assertDontSee(route('transparency'), false) // 透明度報告隱藏
            ->assertDontSee(route('stats'), false);       // 統計地圖隱藏
    }
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=test_footer_shows_published_news_counts_and_hides_removed_links`
Expected: FAIL（目前 footer 仍有 reports/transparency/stats 連結、無分類篇數）。

- [ ] **Step 3: 首頁 route 傳 `newsCounts`**

`routes/web.php:24` 由：
```php
Route::view('/', 'pages.radar')->name('home');
```
改為：
```php
Route::get('/', function () {
    $newsCounts = Cache::remember('hazdar:home:news-counts', 60, fn () => Announcement::published()
        ->selectRaw('category, count(*) as c')
        ->groupBy('category')
        ->orderByRaw('count(*) desc')
        ->pluck('c', 'category')
        ->all());

    return view('pages.radar', ['newsCounts' => $newsCounts]);
})->name('home');
```
（`Announcement` 與 `Cache` 於 `routes/web.php:7,11` 已 import。）

- [ ] **Step 4: 改底帶 + 跑馬燈 + footer**

把 `resources/views/pages/radar.blade.php:66-105`（`{{-- 底部：立即回報 + Footer --}}` 起整個 `<div class="pointer-events-auto ...">`）換成：

```blade
        {{-- 底帶：#002923 實色帶（spec §12.2/12.6）--}}
        <div class="pointer-events-auto flex flex-col gap-3 bg-[#002923] p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-4px_20px_rgba(0,0,0,0.35)]">
            {{-- 管理員公告 --}}
            <livewire:announcement-banner />

            {{-- 我的回報審核結果（已登入才顯示）--}}
            @auth
            <livewire:my-report-status />
            @endauth

            {{-- 跑馬燈廣播（放大到 13px，spec §12.2）--}}
            <div class="mx-auto w-full max-w-md overflow-hidden rounded-full bg-black/30 px-4 py-2">
                <p id="radar-ticker-text"
                   class="radar-ticker truncate text-center text-[13px] font-medium text-slate-100">
                </p>
            </div>

            <button type="button"
                    onclick="window.dispatchEvent(new CustomEvent('hazdar:report-clicked'))"
                    class="mx-auto flex w-full max-w-md items-center justify-center gap-2 rounded-2xl bg-cyan-600 px-6 py-4 text-base font-bold text-white shadow-[0_0_30px_rgba(8,145,178,0.6)] transition active:scale-95 hover:bg-cyan-500">
                <span class="text-xl">⚠️</span>
                <span>{{ __('立即回報災情') }}</span>
            </button>

            <footer class="mx-auto flex w-full max-w-md flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[11px] text-slate-400">
                {{-- 左下：各分類最新消息篇數（只列有已發佈者，整組連 /news，spec §12.6）--}}
                @if (! empty($newsCounts))
                    <a href="{{ route('news') }}" class="flex flex-wrap items-center gap-x-2 gap-y-0.5 hover:text-slate-200">
                        @foreach ($newsCounts as $cat => $n)
                            <span>{{ __(\App\Models\Announcement::CATEGORY_LABELS[$cat] ?? $cat) }} {{ $n }}</span>
                        @endforeach
                    </a>
                @else
                    <a href="{{ route('news') }}" class="hover:text-slate-200">{{ __('最新消息') }}</a>
                @endif

                <span class="text-slate-600">© {{ date('Y') }} Hazdar</span>
            </footer>
        </div>
```

註：原 footer 的回報清單 / 透明度報告 / 統計地圖 / 圖卡專區 / 我的回報 / 聯絡管理員連結全部移除（前三者 route 保留於 `web.php`、只是首頁不連；後三者已改由頂帶 nav 承接，見 Task 2）。

- [ ] **Step 5: 跑測試確認通過**

Run: `php artisan test --compact --filter=HomeChromeTest`
Expected: PASS（四測試全綠）。

- [ ] **Step 6: 修既有 `PromoCardsPageTest`（首頁已不連 /cards）**

本 Phase 頂帶（Task 2）與 footer（Task 3）都移除了首頁的圖卡連結，`PromoCardsPageTest::test_home_page_links_to_cards`（斷言首頁含 `route('cards')` 與「圖卡專區」）已不成立。**不刪測試**（phpunit 規則），改為反映新現況——把 `tests/Feature/PromoCardsPageTest.php` 的 `test_home_page_links_to_cards` 方法整段換成：

```php
    public function test_home_no_longer_links_to_cards_page(): void
    {
        // v0.3：圖卡連結自首頁移除（改首頁 banner，見 Phase 3c）
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertDontSee(route('cards'), false);
    }
```
（`test_active_cards_render_grouped` 暫時保留——`/cards` 頁於 Phase 3c 才移除；屆時 3c 會整檔改寫本測試。）

Run: `php artisan test --compact --filter=PromoCardsPageTest`
Expected: PASS。

- [ ] **Step 7: pint + Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/radar.blade.php tests/Feature/HomeChromeTest.php tests/Feature/PromoCardsPageTest.php
git commit -m "feat: v0.3 底帶實色帶 + 放大跑馬燈 + footer 分類新聞篇數/隱藏連結（spec §12.2/12.6）"
```

---

## 驗證（Phase 3a 收尾）

- 跑 `php artisan test --compact` → 全套綠（Task 3 Step 6 已把 `PromoCardsPageTest::test_home_page_links_to_cards` 改成 `test_home_no_longer_links_to_cards_page`，不留失敗）。
- 前端需 `npm run build` 後以 chrome-devtools 截圖驗證：頂/底為 `#002923` 實色帶、banner 顯示、跑馬燈可讀（≥13px）、footer 顯示分類篇數。

## Self-Review 註記

- **Spec 覆蓋**：§12.1（Task 1）、§12.2 頂/底帶+跑馬燈（Task 2/3）、§12.4 banner（Task 2）、§12.6 footer+nav（Task 2/3）皆有對應 Task。
- **跨 Phase 交接**：Task 3 Step 6 已就地把首頁圖卡連結測試改為「不再連 /cards」，全套保持綠；`/cards` 頁本身與 `test_active_cards_render_grouped` 於 Phase 3c 移除。
- **資料形狀**：`announcements.content` NOT NULL → 測試 create 必帶 `content`。`newsCounts` 為 `array<string,int>`；footer 迴圈 key 用 `Announcement::CATEGORY_LABELS[$cat]`。
