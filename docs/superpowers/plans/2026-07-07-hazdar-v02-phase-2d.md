# Hazdar v0.2 Phase 2d — 首頁與 EOC Dashboard 整合 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 一般使用者進 `/` 即看到整合後的情勢圖：既有全螢幕互動地圖為底，dashboard 的資料面板改為浮動可收合卡片（左右兩欄）；`/dashboard` 302 轉向 `/`；公開 footer 的資料源健康燈號移至 Filament 後台 widget，footer 改以最新消息/公告連結為主（spec §11.1）。

**Architecture:** 首頁與 dashboard **本來就共用同一個 `<livewire:radar-map>`**，整合的本質是「拆掉 eoc-dashboard 自己的地圖與整頁 grid，把左右資料面板改造成浮動卡片層，疊到首頁既有的單一地圖上」。健康指標（§1-5 驗收所需的失敗可視性）在移出公開 footer 前，先落地成 Filament dashboard widget。卡片收合狀態走 Alpine + localStorage，行動版預設收合。

**Tech Stack:** Laravel 13 / Livewire 4 SFC / Filament 5 / Alpine（Livewire 內建，無 collapse 插件——用 x-show/x-transition）/ Chart.js（`window.Chart` 全站已載）/ Tailwind 4 / PHPUnit 12。

## Global Constraints

- 測試 PHPUnit class；`php artisan test --compact tests/...`；commit 前 `php vendor/bin/pint --dirty --format agent`（注意用 `php vendor/bin/pint`，pint bin 曾遇權限問題）。
- Commit `feat:`/`fix:` 前綴 + `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>` trailer。每個 task commit 後 `composer dump-autoload -o`（production、optimize-autoloader；新 class 不 dump 會 500——本 session 已發生過）。
- 共用快取只存純陣列，禁存 Eloquent 物件。
- UI 文字 `__('中文')`；`lang/en.json` 補對照、合法 JSON；`zh_TW.json` 不動。
- **測試請求首頁要帶 `->withHeader('Accept-Language', 'zh-TW')`** 才會渲染中文（middleware 依 Accept-Language；Livewire::test 不走 middleware、用 APP_LOCALE=zh_TW）。
- `git add` 明列檔案或 `-A ':(exclude)public/Hazdar-Banner.png'`（勿掃入該未追蹤檔）。
- 免責彈窗（layouts 內建）、雷達回波、報告表單、分類系統、news/圖卡/資訊彙整（2a–2c 產物）不得回退。
- 地圖只能有一個 `<livewire:radar-map>` 實例（首頁既有的）；整合後 eoc-dashboard 不得再自帶地圖。
- 若某卡片資料源全掛，卡片顯示「資料更新中斷 + 最後更新時間」而非壞版面（spec §1-1，既有 CWA 警告框邏輯沿用）。

---

### Task 1: Filament EOC 健康 widget（承接 §1-5 失敗可視性）

**Files:**
- Create: `app/Filament/Widgets/DataSourceHealthWidget.php`
- Create: `resources/views/filament/widgets/data-source-health.blade.php`
- Test: `tests/Feature/Filament/DataSourceHealthWidgetTest.php`

**Interfaces:**
- Consumes: `App\Services\DataSourceHealth::all()`（既有：回傳 `[source => ['status'=>green|yellow|red, 'label'=>..., 'last_success_at'=>?, 'last_error'=>?]]`；常數 `STATUS_OK='green'`/`STATUS_DELAYED='yellow'`/`STATUS_DOWN='red'`）；`Report`（今日回報數／官方核實數）；`Illuminate\Support\Facades\Queue::size()`
- Produces: Filament admin dashboard 上的健康面板。**這是把 eoc-dashboard 舊 footer 的 health + footerStats 邏輯搬過來的唯一新家**——Task 2 會從 eoc-dashboard 刪掉那些 property，故本 task 必須先完成。

**背景：** Filament panel（`app/Providers/Filament/*.php`）已 `->pages([Dashboard::class])` 且 `->discoverWidgets(in: app_path('Filament/Widgets'), ...)`，但 `app/Filament/Widgets/` 目錄尚不存在。widget 放進去即自動出現在後台首頁。用 Filament v5 的 `Widget`（自訂 view widget，非 stat/chart widget，因要呈現多顆狀態燈）。

- [ ] **Step 1: 寫失敗的測試**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\DataSourceHealthWidget;
use App\Models\Report;
use App\Models\User;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 後台健康 widget（spec §1-5）：scheduled fetch 失敗必須反映在後台狀態燈，
 * 承接原公開 dashboard footer 的角色（§11.1 將公開燈號移入後台）。
 */
class DataSourceHealthWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_source_status_and_today_counts(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $health = app(DataSourceHealth::class);
        $health->recordSuccess('cwa_observations');
        $health->recordFailure('radar_echo', 'timeout');

        Report::factory()->create(['verification_level' => Report::VERIFICATION_OFFICIAL]);
        Report::factory()->create();

        Livewire::test(DataSourceHealthWidget::class)
            ->assertSee('CWA')                 // 某個資料源標籤
            ->assertSee('今日回報')
            ->assertSee('官方核實');
    }

    public function test_down_source_is_visibly_red(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app(DataSourceHealth::class)->recordFailure('radar_echo', 'boom');

        // 從未成功 → STATUS_DOWN；widget 應標記為紅（class 或 red 狀態文字）
        Livewire::test(DataSourceHealthWidget::class)
            ->assertSee(DataSourceHealth::STATUS_DOWN);
    }
}
```

（`User::factory()->admin()` 依既有慣例——先讀 `tests/Feature/Filament/ReportCategoryResourceTest.php` 的 admin helper 與 `UserFactory`。widget 呈現方式若非直接印出 `STATUS_DOWN` 字串，第二個測試改斷言對應的紅色 class 或 last_error 訊息——以實作為準調整，但必須真的區分 down。）

- [ ] **Step 2: 跑測試確認失敗** — widget 類不存在

- [ ] **Step 3: 實作**

`app/Filament/Widgets/DataSourceHealthWidget.php`：

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Report;
use App\Services\DataSourceHealth;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Queue;

class DataSourceHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.data-source-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -3;

    /**
     * @return array{sources: array<string, array{status: string, label: string, last_success_at: ?string, last_error: ?array}>, today_reports: int, official: int, queue_depth: int}
     */
    protected function getViewData(): array
    {
        return [
            'sources' => app(DataSourceHealth::class)->all(),
            'today_reports' => Report::whereDate('created_at', today())->count(),
            'official' => Report::where('verification_level', Report::VERIFICATION_OFFICIAL)->count(),
            'queue_depth' => $this->queueDepth(),
        ];
    }

    private function queueDepth(): int
    {
        try {
            return Queue::size();
        } catch (\Throwable) {
            return 0;
        }
    }
}
```

`resources/views/filament/widgets/data-source-health.blade.php`（比照舊 footer 的狀態燈呈現；Filament widget 用 `<x-filament-widgets::widget>` 外殼）：

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">資料源健康狀態</x-slot>

        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
            @foreach ($sources as $key => $source)
                <span class="flex items-center gap-1.5" title="{{ $key }}{{ $source['last_error'] ? '：'.$source['last_error']['message'] : '' }}">
                    <span @class([
                        'h-2.5 w-2.5 rounded-full',
                        'bg-emerald-500' => $source['status'] === \App\Services\DataSourceHealth::STATUS_OK,
                        'bg-yellow-400' => $source['status'] === \App\Services\DataSourceHealth::STATUS_DELAYED,
                        'bg-rose-500' => $source['status'] === \App\Services\DataSourceHealth::STATUS_DOWN,
                    ])></span>
                    <span class="text-gray-600 dark:text-gray-300">{{ $source['label'] }}</span>
                    <span class="font-mono text-xs text-gray-400" data-status="{{ $source['status'] }}">
                        {{ $source['last_success_at'] ? \Illuminate\Support\Carbon::parse($source['last_success_at'])->timezone('Asia/Taipei')->format('H:i') : '—' }}
                    </span>
                </span>
            @endforeach

            <span class="ml-auto flex items-center gap-4 font-mono text-sm">
                <span>今日回報 <b>{{ $today_reports }}</b></span>
                <span>官方核實 <b class="text-emerald-500">{{ $official }}</b></span>
                <span>Queue <b @class(['text-yellow-500' => $queue_depth > 100])>{{ $queue_depth }}</b></span>
            </span>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
```

（若第二測試斷言 `STATUS_DOWN` 字串，`data-status="{{ $source['status'] }}"` 已輸出 `red`——與 STATUS_DOWN 常數值相符，測試會過。）

- [ ] **Step 4: 測試綠** — `php artisan test --compact tests/Feature/Filament/DataSourceHealthWidgetTest.php`
- [ ] **Step 5: Pint + commit + dump-autoload**（`feat: Filament 資料源健康 widget，承接 §1-5 失敗可視性（spec §11.1）`）

---

### Task 2: eoc-dashboard 元件改造為浮動可收合卡片層

**Files:**
- Modify: `resources/views/livewire/eoc-dashboard.blade.php`（PHP 刪 health/footerStats/queueDepth；template 全面重構）
- Create: `resources/views/livewire/partials/dashboard-card.blade.php`（可收合卡片外殼，Blade component-ish partial）
- Modify: `tests/Feature/EocDashboardTest.php`（改為 `Livewire::test('eoc-dashboard')` 導向；移除 `/dashboard` HTTP 斷言——Task 3 才處理路由）
- Modify: `lang/en.json`
- Test: 同上（EocDashboardTest 改寫）

**Interfaces:**
- Consumes: 保留全部資料 property（weather/rainTrend/rainAccumulated/stats/alerts/infoLinks/openShelterCount）
- Produces: eoc-dashboard 元件**不再自帶地圖、不再有整頁 grid、不再有 health footer、不再有頂部 ticker header**（首頁已有 ticker）。改輸出一組浮動卡片：`<div class="contents">` 內含左欄卡片群 + 右欄卡片群，各自 `pointer-events-auto`。颱風/監測 badge 保留為一顆 compact pill（測試仍斷言 TYPHOON-EMERGENCY／MONITORING）。

**卡片外殼 partial（`resources/views/livewire/partials/dashboard-card.blade.php`）：**

```blade
{{-- 可收合浮動卡片（spec §11.1）：狀態記 localStorage，行動版預設收合。
     用法：@include('livewire.partials.dashboard-card', ['key'=>'weather', 'title'=>'CWA 即時', 'slot'=>...]) 
     —— 因 Blade @include 不吃 slot，改用 x-data 包裝，內容直接寫在呼叫端（見 eoc-dashboard 用法）。 --}}
```

（實作採 inline x-data 而非 partial 抽象——見下方 eoc-dashboard template。partial 檔可省略；若抽出則以 anonymous Blade component `resources/views/components/dashboard-card.blade.php` 帶 `@props(['cardKey','title'])` + `{{ $slot }}`，更符合 Laravel 慣例。**採 anonymous component 版**：）

`resources/views/components/dashboard-card.blade.php`：

```blade
@props(['cardKey', 'title'])

<div class="pointer-events-auto overflow-hidden rounded-xl border border-white/10 bg-[#0f1522]/95 backdrop-blur"
     x-data="{
         open: (() => {
             const v = localStorage.getItem('hazdar:card:{{ $cardKey }}');
             if (v !== null) return v === '1';
             return window.matchMedia('(min-width: 1024px)').matches; // 桌機預設開、行動預設收合
         })(),
         toggle() { this.open = !this.open; localStorage.setItem('hazdar:card:{{ $cardKey }}', this.open ? '1' : '0'); }
     }">
    <button type="button" @click="toggle()"
            class="flex w-full items-center justify-between px-3 py-2 text-[11px] font-semibold tracking-widest text-slate-400">
        <span>{{ $title }}</span>
        <span x-text="open ? '﹀' : '︿'" class="text-slate-500"></span>
    </button>
    <div x-show="open" x-collapse.duration.200ms x-cloak class="px-3 pb-3">
        {{ $slot }}
    </div>
</div>
```

⚠ 驗證：Livewire 4 綁的 Alpine 是否含 `x-collapse`。**先驗**：若 `x-collapse` 不可用（本 repo 無 @alpinejs/collapse 依賴），改用 `x-show="open" x-transition` 即可，不要引新依賴。實作時 grep 確認並二選一。

**eoc-dashboard template 重構（取代 line 170 起整段）：**

外層改為 `<div wire:poll.10s>` 包一個「左欄容器 + 右欄容器」的絕對定位浮動層，疊在首頁地圖上。左欄靠左、右欄靠右，行動版改為單欄堆疊在底部可捲動區。關鍵結構：

```blade
<div wire:poll.10s>
    {{-- 颱風/監測狀態 pill（保留自舊 header） --}}
    @php $typhoon = collect($this->alerts)->contains(fn ($a) => str_contains($a['headline'] ?? '', '颱風')); @endphp
    <div class="pointer-events-none fixed left-1/2 top-16 z-[400] -translate-x-1/2">
        <span class="pointer-events-auto rounded-full px-3 py-1 text-[11px] font-bold {{ $typhoon ? 'bg-rose-600/80 text-white animate-pulse' : 'bg-black/60 text-emerald-300' }}">
            {{ $typhoon ? 'TYPHOON-EMERGENCY' : 'MONITORING' }}
        </span>
    </div>

    {{-- 左欄浮動卡片：CWA 即時 / 24H 雨量 / NCDR 警戒 --}}
    <div class="pointer-events-none fixed left-2 top-28 z-[400] hidden w-[280px] flex-col gap-2 lg:flex">
        <x-dashboard-card card-key="weather" :title="__('CWA 即時觀測')">
            {{-- 既有 weather 卡片內容（208-240 的 stat tiles + 資料中斷警告框）搬進來 --}}
        </x-dashboard-card>
        <x-dashboard-card card-key="rain" :title="__('24H 累積雨量')">
            <div wire:ignore>
                <canvas id="rain-trend-chart" data-trend='@json($this->rainTrend)' class="!h-28"></canvas>
            </div>
            <p class="mt-1 text-center text-[10px] text-slate-500">{{ __('累積') }} {{ number_format($this->rainAccumulated, 1) }} mm</p>
        </x-dashboard-card>
        <x-dashboard-card card-key="alerts" :title="__('NCDR 警戒')">
            {{-- 既有 alerts 列表（249-269）--}}
        </x-dashboard-card>
    </div>

    {{-- 右欄浮動卡片：事件統計 / 交通與清運資訊彙整 --}}
    <div class="pointer-events-none fixed right-2 top-28 z-[400] hidden w-[300px] flex-col gap-2 lg:flex">
        <x-dashboard-card card-key="stats" :title="__('事件統計')">
            {{-- 既有 stats grid（287-302）--}}
        </x-dashboard-card>
        <x-dashboard-card card-key="info" :title="__('交通與清運資訊彙整')">
            {{-- 既有 info board（304-327，含 shelter 徽章）--}}
        </x-dashboard-card>
    </div>

    {{-- 行動版：底部單欄可捲動卡片抽屜（lg 以下顯示；預設各卡收合） --}}
    <div class="pointer-events-none fixed inset-x-0 bottom-24 z-[400] flex flex-col gap-2 px-2 lg:hidden">
        {{-- 同上五張卡片，行動版；可用同一組 x-dashboard-card（localStorage key 相同，跨裝置一致） --}}
    </div>
</div>
```

**實作要點：**
- 內層卡片內容從既有 template 對應行段**原樣搬移**（stat tiles、資料中斷警告框、rain canvas、alert 列表、stats grid、info board）——只改外殼，不改資料呈現邏輯。
- 行動版與桌機版共用五張卡片內容：為避免重複 markup，可把每張卡的「內容」抽成 `@include` partial（`resources/views/livewire/partials/card-weather.blade.php` 等五個），桌機/行動兩處各 include 同一個。**建議抽 partial**，避免 DOM id `rain-trend-chart` 在桌機+行動重複——**只在其中一處（桌機或依 viewport 擇一）渲染 canvas**，或給行動版 canvas 不同 id 並讓 Chart 腳本各自初始化。最簡解：**rain canvas 只在桌機欄渲染一次**，行動版該卡顯示 `累積 N mm` 純數字不畫圖（避免重複 id 與雙 Chart 實例）。
- 刪除 PHP 的 `getHealthProperty`/`getFooterStatsProperty`/`queueDepth`（已移入 Task 1 widget）；確認元件內無其他引用（探勘已確認無）。
- 刪除舊的整頁 grid、中央地圖 section（272-283）、頂部 ticker header（保留 typhoon pill 邏輯，捨棄重複的 `#radar-ticker-text`）、health footer（332-349）。
- z-index：卡片層 `z-[400]`（低於首頁浮動 UI header/chips 的 `z-[500]`，避免蓋住頂部品牌與分類鈕；高於地圖 `z-0` 與戰術格線 `z-[1]`）。

**EocDashboardTest 改寫：** 移除所有 `$this->get('/dashboard')` 呼叫（Task 3 才定案路由），改為 `Livewire::test('eoc-dashboard')`：

```php
public function test_dashboard_component_renders_stats(): void
{
    // ...seed reports...
    Livewire::test('eoc-dashboard')->assertSee('事件統計')->assertSee('CWA 即時觀測');
}

public function test_typhoon_badge_when_typhoon_alert_active(): void
{
    ExternalAlert::factory()->create([/* headline 含 颱風, active, coversTaipei */]);
    Livewire::test('eoc-dashboard')->assertSee('TYPHOON-EMERGENCY');
}

public function test_monitoring_badge_without_typhoon(): void
{
    Livewire::test('eoc-dashboard')->assertSee('MONITORING')->assertDontSee('TYPHOON-EMERGENCY');
}
```

（既有的 `->get('stats')`/`->get('weather')`/`->get('health')` computed-property 讀取測試：`stats`/`weather` 保留；`health` 相關的斷言移除或搬到 DataSourceHealthWidgetTest。ExternalAlert factory 是否存在先查。）

- [ ] Steps：先驗 x-collapse 可用性 → 測試改寫先紅 → 抽卡片內容 partial + 重構 template + 刪 PHP dead property → `Livewire::test('eoc-dashboard')` 綠 + 全套綠（此時 `/dashboard` 頁仍在、仍渲染舊 wrapper？——注意 `pages/dashboard.blade.php` 仍 include eoc-dashboard，會渲染成浮動卡片但沒有地圖底；這是暫態，Task 3 收斂。若 dashboard.blade.php 的既有測試因此壞，本 task 可暫時將其 skip 並註明，Task 3 修正） → Pint → commit（`feat: eoc-dashboard 改造為浮動可收合卡片（spec §11.1）`）→ dump-autoload

---

### Task 3: 首頁整合 + `/dashboard` 302 轉向 + footer 收斂

**Files:**
- Modify: `resources/views/pages/radar.blade.php`（加 `<livewire:eoc-dashboard />` 浮動卡片層；port rain-trend Chart.js 腳本；footer 移除自指的「EOC 戰情室」連結）
- Modify: `routes/web.php`（`/dashboard` → `Route::redirect` 302，保留 name）
- Delete: `resources/views/pages/dashboard.blade.php`（路由轉向後不再使用）
- Modify: `tests/Feature/EocDashboardTest.php`（補 `/dashboard` 轉向斷言、`/` 首頁渲染卡片斷言）
- Modify: `lang/en.json`（如需）
- Test: 同上

**Interfaces:**
- Consumes: Task 2 的浮動卡片版 eoc-dashboard
- Produces: `/` 首頁即情勢圖；`/dashboard` 302→`/`（name `dashboard` 保留，避免其他 `route('dashboard')` 引用爆掉——探勘確認僅 radar footer 一處，一併移除）

- [ ] **Step 1: 補失敗的測試（EocDashboardTest）**

```php
public function test_dashboard_url_redirects_to_home(): void
{
    $this->get('/dashboard')->assertRedirect('/');
}

public function test_home_page_embeds_dashboard_cards(): void
{
    $this->withHeader('Accept-Language', 'zh-TW')->get('/')
        ->assertOk()
        ->assertSee('CWA 即時觀測')
        ->assertSee('交通與清運資訊彙整')
        ->assertSee('data-trend', false);   // rain canvas 在首頁
}
```

- [ ] **Step 2: 跑測試確認失敗**

- [ ] **Step 3: 實作**

`routes/web.php` line 263 `Route::view('/dashboard', 'pages.dashboard')->name('dashboard');` 改：

```php
// EOC dashboard 已整合進首頁（spec §11.1）；舊網址 302 轉向
Route::redirect('/dashboard', '/', 302)->name('dashboard');
```

`resources/views/pages/radar.blade.php`：
- 在 `<livewire:radar-map />`（line 6）之後、浮動 UI wrapper 之前或之內適當層，加 `<livewire:eoc-dashboard />`（它自身已是 fixed 定位的浮動卡片層，放在 `radar-page` root div 內即可，不需塞進 `z-[500]` header wrapper）。
- footer（87-102）移除 `<a href="{{ route('dashboard') }}">EOC 戰情室</a>` 那一行（轉向後自指）。其餘連結（最新消息/回報清單/透明度/統計/圖卡/我的回報/聯絡）保留。
- port Chart.js 腳本：把 `pages/dashboard.blade.php` 的 `<script>`（12-39）搬到 radar.blade.php 底部（`@section('content')` 內、`@endsection` 前）。**不要**帶 `<meta http-equiv="refresh" content="600">`（會打斷首頁互動）。

`resources/views/pages/dashboard.blade.php` 刪除。

- [ ] **Step 4: 測試 + build + 全套**

```bash
php artisan test --compact tests/Feature/EocDashboardTest.php
npm run build
php artisan test --compact
```

- [ ] **Step 5: Pint + commit + dump-autoload**（`feat: 首頁整合 EOC 卡片 + /dashboard 302 轉向（spec §11.1）`）

---

### Task 4: Phase 2d 收尾驗證

- [ ] `php artisan test --compact` 全綠（Rule 12：有紅修完）。
- [ ] `npm run build`。
- [ ] 線上冒煙：`/`、`/dashboard`（應 302→`/`）、Filament 後台首頁（健康 widget）。
- [ ] 瀏覽器目測（chrome-devtools）：桌機首頁左右卡片展開、﹀ 收合、reload 後狀態保留（localStorage）；行動視窗（resize 375px）卡片預設收合；地圖與回報流程不被卡片擋住；免責彈窗、雷達回波、分類 chips、三態仍運作。
- [ ] Ledger checkpoint + Phase 2d final review（whole-branch，fable）。
- [ ] 更新 spec §11.10 Phase 2d 標記完成；彙整 v0.2 全 phase 完成摘要。
