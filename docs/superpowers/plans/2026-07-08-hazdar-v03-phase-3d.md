# Hazdar v0.3 Phase 3d — 手機右側 IITC 面板 + 卡片 modal 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 手機版把「上方 chips + 下方互疊卡片抽屜」改成右緣可滑出的 IITC 式面板：上半為圖層 checkbox（分類 + 收容所 + 雷達回波 + 淹水潛勢 + 回報/已通報/已排除三態），下半為各資訊卡按鈕（點開全螢幕 bottom-sheet modal）+ 圖卡 banner。桌機維持原浮動卡片與 chips。

**Architecture:** 桌機 chips 加 `hidden lg:flex` 只在桌機顯示；`initChips` 改為「同 `data-map-chip` 全部同步」讓桌機 chip 與面板 checkbox 共用狀態。面板放在 `eoc-dashboard.blade.php`（能存取 `$this->weather/stats/…/promoCards`），取代原行動抽屜（217-235 行）。圖層 checkbox 沿用 `data-map-chip` 機制（`initChips` 自動綁定，無需新 JS）；卡片以 inline Alpine 的 `activeCard` 開 bottom-sheet modal。面板/checkbox/modal 樣式進 `app.css`，並放寬手機版 Leaflet 控制項上邊距（chips 移走後不需大留白）。

**Tech Stack:** Alpine.js（inline x-data）、Leaflet 控制項定位、Tailwind + `app.css`、Livewire test。面板互動以 chrome-devtools 實機驗收。

## Global Constraints（spec §12.7，逐條照抄）

- 面板為 mobile-only（`lg:hidden`）；右緣收合 tab（⟩）點擊滑出。
- 上半圖層 checkbox：各啟用分類 + 收容所 + 雷達回波 + 淹水潛勢（有設定才列）+ 三態（回報/已通報/已排除）；每個 checkbox 驅動與桌機 chips 相同的 `data-map-chip` 開關，狀態與地圖圖層單一同步。
- 下半：各資訊卡按鈕 → 全螢幕 bottom-sheet modal（CWA 即時 / 24H 雨量 / NCDR 警戒 / 事件統計 / 交通清運）+ 圖卡 banner（§12.5，沿用 `card-promo`）。
- 手機不再顯示頂部 chips（桌機 chips 保留）；取代 `eoc-dashboard.blade.php` `lg:hidden` 的行動抽屜。
- 地圖搜尋/明暗/縮放留 Leaflet 原位；右側 tab 置右中緣避開之。
- 桌機（`lg` 以上）行為與外觀**完全不變**。
- 前端改動需 `npm run build` 才反映。

## 現況參考（實作前必讀）

- `resources/views/pages/radar.blade.php:46-62` — 頂部 chips 容器（第 47 行 class `pointer-events-auto flex flex-wrap gap-1.5 px-4`；本 Phase 加 `hidden lg:flex`）。
- `resources/js/app.js:398-425` — `initChips`（Phase 3b 已改 `layerToggles`；本 Phase 改點擊 handler 為多元素同步）。
- `resources/views/livewire/eoc-dashboard.blade.php:195-215` — 桌機左右浮動卡片欄（**不動**）。
- `resources/views/livewire/eoc-dashboard.blade.php:217-235` — 行動版底部抽屜（本 Phase 整段換成右側面板 + modals）。
- 卡片局部視圖：`livewire.partials.card-weather` / `card-rain`（吃 `$withChart`）/ `card-alerts` / `card-stats` / `card-info` / `card-promo`（Phase 3c 建）。
- `resources/css/app.css:66-102` — Leaflet 控制項定位（含 `@media (max-width:640px)` 的 156px/120px 大留白，本 Phase 放寬）。
- `resources/css/app.css:133-155` — `.map-chip` / `.chip-on`（面板 checkbox 沿用 `chip-on` 狀態類）。
- Alpine 收合狀態範式見 `resources/views/components/dashboard-card.blade.php`（`x-data` + localStorage）。

---

### Task 1: 桌機 chips 手機隱藏 + `initChips` 多元素同步

**Files:**
- Modify: `resources/views/pages/radar.blade.php:47`（chips 容器加 `hidden lg:flex`）
- Modify: `resources/js/app.js:407-424`（`initChips` 點擊 handler 改多元素同步）
- Test: `tests/Feature/MobilePanelTest.php`（新建，含 chips 隱藏斷言）

**Interfaces:**
- Produces: 全站同 `data-map-chip="X"` 的元素（桌機 chip + 面板 checkbox）點擊後 `chip-on` 類同步；桌機 chips 容器於 `<lg` 隱藏。

- [ ] **Step 1: 寫失敗測試**

建立 `tests/Feature/MobilePanelTest.php`：

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 手機右側 IITC 圖層/資訊面板（spec §12.7）。面板互動屬 JS/Alpine，
 * 由瀏覽器驗收；此處守「桌機 chips 手機隱藏」與「面板結構是否渲染」。
 */
class MobilePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_chips_hidden_on_mobile(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('lg:flex-wrap', false); // chips 容器改為 hidden lg:flex lg:flex-wrap
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=test_desktop_chips_hidden_on_mobile`
Expected: FAIL（chips 容器目前是 `flex flex-wrap`，無 `lg:flex-wrap`）。

- [ ] **Step 3: chips 容器加 `hidden lg:flex`**

`resources/views/pages/radar.blade.php:47`，把：
```blade
        <div class="pointer-events-auto flex flex-wrap gap-1.5 px-4">
```
改為：
```blade
        <div class="pointer-events-auto hidden gap-1.5 px-4 lg:flex lg:flex-wrap">
```

- [ ] **Step 4: `initChips` 點擊 handler 改多元素同步**

把 `resources/js/app.js:407-424`（`document.querySelectorAll('[data-map-chip]').forEach(...)` 整段）換成：

```js
    // 同 data-map-chip 的所有元素（桌機 chip + 面板 checkbox）狀態同步（spec §12.7）
    const syncChip = (cat, on) => {
        document.querySelectorAll(`[data-map-chip="${cat}"]`).forEach((el) => el.classList.toggle('chip-on', on));
    };

    document.querySelectorAll('[data-map-chip]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const cat = btn.dataset.mapChip;
            const on = ! btn.classList.contains('chip-on');
            if (layerToggles[cat]) {
                syncChip(cat, on);
                layerToggles[cat](on);
                return;
            }
            if (on) {
                activeCategories.add(cat);
            } else {
                activeCategories.delete(cat);
            }
            syncChip(cat, on);
            scheduleFetch();
        });
    });
```

- [ ] **Step 5: build + 測試**

```bash
npm run build
php artisan test --compact --filter=MobilePanelTest
```
Expected: PASS。

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/radar.blade.php resources/js/app.js tests/Feature/MobilePanelTest.php
git commit -m "feat: v0.3 桌機 chips 手機隱藏 + initChips 同名圖層多元素同步（spec §12.7）"
```

---

### Task 2: 右側 IITC 面板（圖層 checkbox + 卡片按鈕 + modal + 圖卡 banner）

**Files:**
- Modify: `resources/views/livewire/eoc-dashboard.blade.php:217-235`（整段行動抽屜換成面板 + modals）
- Modify: `resources/css/app.css`（尾端新增 `.layer-check` / `.card-open-btn` 樣式）
- Test: `tests/Feature/MobilePanelTest.php`（延續）

**Interfaces:**
- Consumes: `data-map-chip` 綁定（Task 1）；`$this->weather/rainTrend/stats/alerts/infoLinks/openShelterCount/promoCards`（eoc-dashboard 既有）；卡片局部視圖（既有）；`card-promo`（Phase 3c）。
- Produces: 面板含 `data-map-chip` 圖層 checkbox 與 `.card-open-btn` 卡片鈕、`activeCard` 控制的 bottom-sheet modals。

- [ ] **Step 1: 寫失敗測試**（追加到 `MobilePanelTest`）

```php
    public function test_mobile_panel_renders_layer_toggles_and_card_buttons(): void
    {
        Livewire::test('eoc-dashboard')
            ->assertSee('圖層')                                  // 面板圖層區標題（面板專有）
            ->assertSee('狀態')                                  // 面板三態區標題（面板專有）
            ->assertSee('data-map-chip="notified"', false)       // 面板三態 checkbox（本元件僅面板有）
            ->assertSee('data-map-chip="shelters"', false)       // 面板收容所 checkbox
            ->assertSee('card-open-btn', false);                 // 面板卡片按鈕
    }
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=test_mobile_panel_renders_layer_toggles_and_card_buttons`
Expected: FAIL（尚無面板）。

- [ ] **Step 3: 換掉行動抽屜為右側面板**

把 `resources/views/livewire/eoc-dashboard.blade.php:217-235`（`{{-- 行動版：底部單欄可捲動卡片抽屜 …--}}` 起整個 `<div class="pointer-events-none fixed inset-x-0 bottom-24 …">…</div>`）整段換成：

```blade
    {{-- 行動版右側 IITC 圖層/資訊面板（spec §12.7）：取代舊底部抽屜 --}}
    <div class="lg:hidden" x-data="{ panelOpen: false, activeCard: null }">
        {{-- 右緣收合 tab --}}
        <button type="button" @click="panelOpen = ! panelOpen"
                class="pointer-events-auto fixed right-0 top-1/2 z-[510] -translate-y-1/2 rounded-l-lg bg-[#002923] px-1.5 py-5 text-lg text-slate-200 shadow-lg shadow-black/40"
                :aria-expanded="panelOpen">
            <span x-text="panelOpen ? '›' : '‹'"></span>
        </button>

        {{-- 面板 --}}
        <div x-show="panelOpen" x-cloak x-transition.origin.right
             class="pointer-events-auto fixed bottom-24 right-0 top-16 z-[505] flex w-64 flex-col gap-3 overflow-y-auto rounded-l-xl border-l border-white/10 bg-[#002923]/98 p-3 backdrop-blur">
            {{-- 圖層 --}}
            <div>
                <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('圖層') }}</p>
                <div class="flex flex-col gap-1">
                    @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                        <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
                                class="layer-check chip-on">{{ $cat->emoji }} {{ __($cat->name) }}</button>
                    @endforeach
                    <button type="button" data-map-chip="shelters" style="--chip-color:#4ade80" class="layer-check">🏠 {{ __('收容所') }}</button>
                    <button type="button" data-map-chip="radar_echo" style="--chip-color:#22d3ee" class="layer-check">🌀 {{ __('雷達回波') }}</button>
                    @if (config('hazdar.flood_potential.xyz') || config('hazdar.flood_potential.wms_url'))
                        <button type="button" data-map-chip="flood_potential" style="--chip-color:#818cf8" class="layer-check">🗺️ {{ __('淹水潛勢') }}</button>
                    @endif
                </div>
            </div>
            {{-- 狀態三態 --}}
            <div>
                <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('狀態') }}</p>
                <div class="flex flex-col gap-1">
                    <button type="button" data-map-chip="reported" style="--chip-color:#22d3ee" class="layer-check chip-on">📍 {{ __('回報') }}</button>
                    <button type="button" data-map-chip="notified" style="--chip-color:#818cf8" class="layer-check chip-on">📣 {{ __('已通報') }}</button>
                    <button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8" class="layer-check chip-on">✅ {{ __('已排除') }}</button>
                </div>
            </div>
            {{-- 資訊卡按鈕 --}}
            <div>
                <p class="mb-1 text-[11px] font-bold tracking-widest text-slate-400">{{ __('資訊') }}</p>
                <div class="flex flex-col gap-1">
                    <button type="button" @click="activeCard = 'weather'" class="card-open-btn">{{ __('CWA 即時觀測') }}</button>
                    <button type="button" @click="activeCard = 'rain'" class="card-open-btn">{{ __('24H 累積雨量') }}</button>
                    <button type="button" @click="activeCard = 'alerts'" class="card-open-btn">{{ __('NCDR 警戒') }}</button>
                    <button type="button" @click="activeCard = 'stats'" class="card-open-btn">{{ __('事件統計') }}</button>
                    <button type="button" @click="activeCard = 'info'" class="card-open-btn">{{ __('交通與清運資訊彙整') }}</button>
                </div>
            </div>
            {{-- 圖卡 banner（spec §12.5，行動版改置於面板內）--}}
            @include('livewire.partials.card-promo')
        </div>

        {{-- 卡片 bottom-sheet modals --}}
        @php
            $mobileCards = [
                'weather' => ['CWA 即時觀測', 'livewire.partials.card-weather', []],
                'rain' => ['24H 累積雨量', 'livewire.partials.card-rain', ['withChart' => false]],
                'alerts' => ['NCDR 警戒', 'livewire.partials.card-alerts', []],
                'stats' => ['事件統計', 'livewire.partials.card-stats', []],
                'info' => ['交通與清運資訊彙整', 'livewire.partials.card-info', []],
            ];
        @endphp
        @foreach ($mobileCards as $key => [$cardTitle, $partial, $partialData])
            <div x-show="activeCard === '{{ $key }}'" x-cloak @click.self="activeCard = null"
                 class="pointer-events-auto fixed inset-0 z-[550] flex items-end bg-black/50">
                <div class="max-h-[75dvh] w-full overflow-y-auto rounded-t-2xl bg-[#003a30] p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-200">{{ __($cardTitle) }}</h3>
                        <button type="button" @click="activeCard = null" class="text-lg text-slate-400 hover:text-slate-200">✕</button>
                    </div>
                    @include($partial, $partialData)
                </div>
            </div>
        @endforeach
    </div>
```

- [ ] **Step 4: 新增面板 CSS**

在 `resources/css/app.css` 檔尾新增：

```css
/* ── 行動版右側圖層面板（spec §12.7）：checkbox 式圖層列 + 卡片開啟鈕 ── */
.layer-check {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    color: #94a3b8;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    text-align: left;
}
.layer-check::before {
    content: '☐';
    font-size: 15px;
    line-height: 1;
    color: #64748b;
}
.layer-check.chip-on {
    color: #f8fafc;
    border-color: color-mix(in srgb, var(--chip-color) 60%, transparent);
    background: color-mix(in srgb, var(--chip-color) 18%, rgba(255, 255, 255, 0.03));
}
.layer-check.chip-on::before {
    content: '☑';
    color: var(--chip-color);
}
.card-open-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 10px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    color: #cbd5e1;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    text-align: left;
}
.card-open-btn::after { content: '›'; color: #64748b; }
.card-open-btn:hover { background: rgba(255, 255, 255, 0.08); }
```

- [ ] **Step 5: build + 測試 + 瀏覽器驗收（含 poll 存活檢查）**

```bash
npm run build
php artisan test --compact --filter=MobilePanelTest
```
Expected: 測試 PASS。以 chrome-devtools `resize_page` 到 390×844（手機）開 `/`：
1. 頂部無 chips；右緣有 tab，點擊滑出面板。
2. 圖層 checkbox 勾/取消可即時開關地圖圖層（勾三態、收容所、雷達回波皆生效）。
3. 點資訊卡按鈕開 bottom-sheet modal，內容正確，✕ 可關。
4. **關鍵**：面板開著時等 ≥10s（`wire:poll.10s` 觸發一次）確認面板**不會自動關閉**、`activeCard` 不被重置。若會被重置，於面板最外層 `x-data` 改用 localStorage 持久化（比照 `dashboard-card`：`panelOpen: localStorage.getItem('hazdar:panel:open')==='1'`、開關時寫回），再重驗。
5. 地圖搜尋放大鏡 / 明暗切換 / 縮放 +− 不被面板或 tab 遮擋。

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/eoc-dashboard.blade.php resources/css/app.css tests/Feature/MobilePanelTest.php
git commit -m "feat: v0.3 手機右側 IITC 圖層/資訊面板 + 卡片 bottom-sheet modal（spec §12.7）"
```

---

### Task 3: 放寬手機版 Leaflet 控制項留白 + 收尾驗證

**Files:**
- Modify: `resources/css/app.css:83-92`（`@media (max-width:640px)` 留白）

**Interfaces:**
- Consumes: Task 1 已移走手機頂部 chips。
- Produces: 手機控制項上移貼近頂帶下緣。

- [ ] **Step 1: 放寬手機留白**

把 `resources/css/app.css:83-92` 的 `@media (max-width: 640px)` 區塊：
```css
@media (max-width: 640px) {
    /* 手機：header + 兩排 chips 約 130px，下推至 156px 完全避開 */
    .radar-page #radar-map .leaflet-top.leaflet-left {
        margin-top: 156px;
    }
    /* 右上縮放同步下移，避免與右側使用者/登入 pill 重疊 */
    .radar-page #radar-map .leaflet-top.leaflet-right .leaflet-control-zoom {
        margin-top: 120px;
    }
}
```
改為：
```css
@media (max-width: 640px) {
    /* v0.3：手機頂部 chips 已移入右側面板，控制項只需避開 ~56px 頂帶 */
    .radar-page #radar-map .leaflet-top.leaflet-left {
        margin-top: 72px;
    }
    .radar-page #radar-map .leaflet-top.leaflet-right .leaflet-control-zoom {
        margin-top: 72px;
    }
}
```

- [ ] **Step 2: build + 全面瀏覽器驗收**

```bash
npm run build
php artisan test --compact
```
Expected: 全套綠。chrome-devtools 手機視窗（390×844）：搜尋放大鏡 / 明暗切換緊貼頂帶下方、右上縮放不被使用者 pill 遮擋、面板 tab 與地圖控制項互不重疊；桌機視窗（1280 寬）確認 chips、浮動卡片、控制項留白與 v0.2 一致（無回歸）。

- [ ] **Step 3: Commit**

```bash
git add resources/css/app.css
git commit -m "feat: v0.3 放寬手機 Leaflet 控制項留白（chips 已移入面板，spec §12.7）"
```

---

## 驗證（Phase 3d 收尾）

- `php artisan test --compact` → 全套綠。
- chrome-devtools 手機/桌機雙視窗驗收上述互動與無重疊。
- 桌機外觀/行為與 v0.2 一致（面板 `lg:hidden`、chips `hidden lg:flex`）。

## Self-Review 註記

- **Spec 覆蓋**：§12.7 面板（Task 2）、chips 手機隱藏 + 狀態同步（Task 1）、控制項留白（Task 3）全覆蓋。
- **狀態同步**：桌機 chip 與面板 checkbox 共用 `data-map-chip` + `chip-on` 類，`initChips` 的 `syncChip` 保證兩者不脫鉤（即使因 resize 同時存在）。
- **poll 存活風險**：面板 Alpine 狀態可能被 `wire:poll.10s` morph 重置——Task 2 Step 5 明列瀏覽器檢查與 localStorage 後備方案，勿默默略過（Rule 12 fail loud）。
- **型別/命名一致**：`data-map-chip` 值（含 `reported/notified/resolved/shelters/radar_echo/flood_potential` 與各分類 slug）與 Phase 3b `layerToggles`、`app.js` 對應；卡片局部視圖名與現有一致；`$mobileCards` 解構 `[$cardTitle, $partial, $partialData]`。
- **桌機不動**：所有面板/隱藏皆以 `lg:` 斷點隔離。
