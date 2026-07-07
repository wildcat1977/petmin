# Hazdar v0.3 Phase 3b — 地圖三態 pin + 三態篩選 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把地圖上的 `L.circleMarker` 圓圈換成「分類色水滴 pin + 分類 emoji」，用 `status` 表現三態（回報=實色 / 已通報=20% 透明 / 已排除=灰階）；查核等級退出地圖只留 popup；並把單一「已排除」chip 拆成「回報 / 已通報 / 已排除」三態篩選。

**Architecture:** 主要動 `resources/js/app.js`（marker 渲染 + `activeStatuses` 過濾 + chip 綁定）與 `resources/views/pages/radar.blade.php`（三態 chip）。`categoryMeta` payload 已含 `emoji`（`radar-map.blade.php:70`），只需 JS 端讀取。pin 視覺以 chrome-devtools 瀏覽器截圖驗收；可 PHP 測者為 payload 契約與三態 chip 是否渲染。

**Tech Stack:** Leaflet 1.9（`L.divIcon` / `L.marker`）、Vite/JS、Tailwind blade、PHPUnit。

## Global Constraints（spec §12.3 / §12.8，逐條照抄）

- pin = 分類色水滴（SVG/CSS），中央放該分類 `emoji`，尖端 `iconAnchor` 對座標。
- 三態由 `status`（`p.st`）決定：`reported`(`null`)=實色 `opacity 1`；`notified`=整枚 `opacity 0.2`；`resolved`=`filter: grayscale(1)`。
- `verification_level` **退出地圖視覺**，只留 popup（popup 現有顯示不得動）；`VERIFICATION_STYLE` 於 marker 渲染移除。
- `CATEGORY_META` 補 `emoji`；聚合圓 `buildClustersLayer` 維持數字徽章不變。
- 三態篩選：桌機單一「已排除」chip → 三態 chip（回報 / 已通報 / 已排除，皆預設開）。**已排除**沿用 `show_resolved` 伺服端參數（隱藏時不下發已排除點，不變）；**回報 / 已通報**以 `activeStatuses` set 客戶端過濾已下發的 active 點。
- 禁止改動點位 API（`MapPointsController`）、回報寫入、後台。
- 前端改動需 `npm run build` 後才反映於瀏覽器。

## 現況參考（實作前必讀）

- `resources/js/app.js:111-117` — `CATEGORY_META` 由 `data-categories` 建立（目前欄位：`color/label/active/extra`，**缺 emoji**）。
- `resources/js/app.js:119-124` — `VERIFICATION_STYLE`（本 Phase 移除，僅 `buildPointsLayer` 用它）。
- `resources/js/app.js:126-130` — `VERIFICATION_LABEL`（popup 用，**保留**）。
- `resources/js/app.js:139-141` — `activeCategories` / `showResolved` 宣告處。
- `resources/js/app.js:196-201` — popup 內 `p.st` 已通報/已排除文字（保留）。
- `resources/js/app.js:218-238` — `buildPointsLayer`（`L.circleMarker`，本 Phase 改寫）。
- `resources/js/app.js:240-259` — `buildClustersLayer`（不動）。
- `resources/js/app.js:398-425` — `initChips` / `layerToggles`（`resolved` 已在 404 行；本 Phase 加 `reported`/`notified`）。
- `resources/views/pages/radar.blade.php:54-55` — 單一「已排除」chip（本 Phase 換三態）。
- `resources/views/livewire/radar-map.blade.php:65-78` — `categoryMeta` 已含 `emoji`（payload 端無需改）。

---

### Task 1: 三態 pin 渲染（`CATEGORY_META` 補 emoji + `buildPointsLayer` 改 divIcon）

**Files:**
- Modify: `resources/js/app.js:115`（emoji）、`app.js:119-124`（刪 `VERIFICATION_STYLE`）、`app.js:139-141`（加 `activeStatuses`）、`app.js:218-238`（`buildPointsLayer` + 新 `pinIcon`）
- Test: `tests/Feature/MapMarkerTest.php`（新建，測 payload 契約）

**Interfaces:**
- Consumes: `data-categories` JSON 每筆含 `emoji`（既有）。
- Produces: 全域 `activeStatuses`（`Set<'reported'|'notified'|'resolved'>`，預設三者皆在）；`function pinIcon(meta, status)` 回傳 `L.divIcon`；`buildPointsLayer` 依 `activeStatuses` 過濾。

- [ ] **Step 1: 寫失敗測試**

建立 `tests/Feature/MapMarkerTest.php`：

```php
<?php

namespace Tests\Feature;

use App\Models\ReportCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 地圖三態 pin + 三態篩選（spec §12.3/§12.8）。
 * pin 視覺屬 JS，由瀏覽器驗收；此處守 payload 契約與三態 chip 是否渲染。
 */
class MapMarkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_payload_carries_emoji_for_pins(): void
    {
        ReportCategory::create([
            'slug' => 'test_cat', 'name' => '測試類', 'emoji' => '🧪',
            'color' => '#abcdef', 'sort_order' => 99, 'is_active' => true,
        ]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('"emoji"', false)   // categoryMeta payload 供 pinIcon 使用
            ->assertSee('🧪', false);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗（或已綠則說明）**

Run: `php artisan test --compact --filter=test_category_payload_carries_emoji_for_pins`
Expected: PASS（payload 端本就含 emoji）。**若已 PASS**，此測試作為契約守門（防未來有人拿掉 payload emoji）；仍保留。若 FAIL 表示種子分類未載入，於 Step 內用上方 `ReportCategory::create` 確保至少一筆。

- [ ] **Step 3: 改 `CATEGORY_META` 補 emoji**

`resources/js/app.js:115`：
```js
        CATEGORY_META[c.slug] = { color: c.color, emoji: c.emoji, label: () => c.name, active: c.active, extra: c.extra };
```

- [ ] **Step 4: 刪除 `VERIFICATION_STYLE` const**

刪除 `resources/js/app.js:119-124`（整個 `VERIFICATION_STYLE` 物件與其上方註解 `// 視覺分層…` 那行）。**保留** `VERIFICATION_LABEL`（126-130）。

- [ ] **Step 5: 加 `activeStatuses` 宣告**

在 `resources/js/app.js:141`（`let showResolved = true;` 之後）加：
```js
// 回報三態篩選（spec §12.8）：reported/notified 純前端過濾已下發點；
// resolved 另由 showResolved 伺服端 gating（隱藏時根本不下發已排除點）。
let activeStatuses = new Set(['reported', 'notified', 'resolved']);
```

- [ ] **Step 6: 改寫 `buildPointsLayer` + 新增 `pinIcon`**

把 `resources/js/app.js:218-238`（整個 `buildPointsLayer`）換成：

```js
// 分類色水滴 pin（spec §12.3）：中置分類 emoji；三態 reported=實色 / notified=20% / resolved=灰階
function pinIcon(meta, status) {
    const color = meta?.color ?? '#94a3b8';
    const emoji = meta?.emoji ?? '📍';
    const opacity = status === 'notified' ? 0.2 : 1;
    const grayscale = status === 'resolved' ? 'grayscale(1)' : 'none';
    const html =
        `<div style="opacity:${opacity};filter:${grayscale}">` +
          `<div style="width:28px;height:28px;background:${color};border:2px solid #fff;` +
          `border-radius:50% 50% 50% 0;transform:rotate(-45deg);` +
          `box-shadow:0 2px 6px rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center">` +
            `<span style="transform:rotate(45deg);font-size:14px;line-height:1">${emoji}</span>` +
          `</div>` +
        `</div>`;
    return L.divIcon({ html, className: '', iconSize: [28, 34], iconAnchor: [14, 30], popupAnchor: [0, -28] });
}

function buildPointsLayer(points) {
    const layer = L.layerGroup();
    points.forEach((p) => {
        const status = p.st ?? 'reported';
        if (! activeStatuses.has(status)) return; // 三態篩選（spec §12.8）
        const meta = CATEGORY_META[p.cat];
        const marker = L.marker([p.lat, p.lng], { icon: pinIcon(meta, status) });
        marker.bindPopup(createPopupEl(p), { maxWidth: 280 });
        layer.addLayer(marker);
    });
    return layer;
}
```

- [ ] **Step 7: build + 測試 + 瀏覽器驗收**

```bash
npm run build
php artisan test --compact --filter=MapMarkerTest
```
Expected: 測試 PASS。以 chrome-devtools 開 `/`：確認點位為分類色水滴 pin、中置 emoji；已通報點半透明、已排除點灰階；點擊 popup 仍顯示查核層級文字。

- [ ] **Step 8: Commit**

```bash
git add resources/js/app.js tests/Feature/MapMarkerTest.php
git commit -m "feat: v0.3 地圖點位改分類色水滴 pin + emoji 三態，查核等級只留 popup（spec §12.3）"
```

---

### Task 2: 三態篩選 chip（回報 / 已通報 / 已排除）

**Files:**
- Modify: `resources/views/pages/radar.blade.php:54-55`（單一 chip → 三態）
- Modify: `resources/js/app.js:399-405`（`initChips` `layerToggles` 加 `reported`/`notified`）
- Test: `tests/Feature/MapMarkerTest.php`（延續）

**Interfaces:**
- Consumes: Task 1 的 `activeStatuses` 與 `scheduleFetch`（既有）。
- Produces: 三個 `data-map-chip="reported|notified|resolved"` 按鈕；`layerToggles` 對三態的處理。

- [ ] **Step 1: 寫失敗測試**（追加到 `MapMarkerTest`）

```php
    public function test_three_status_filter_chips_render(): void
    {
        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('data-map-chip="reported"', false)
            ->assertSee('data-map-chip="notified"', false)
            ->assertSee('data-map-chip="resolved"', false);
    }
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact --filter=test_three_status_filter_chips_render`
Expected: FAIL（目前只有 `resolved` chip）。

- [ ] **Step 3: 改 blade 三態 chip**

把 `resources/views/pages/radar.blade.php:54-55` 換成：

```blade
            <button type="button" data-map-chip="reported" style="--chip-color:#22d3ee"
                    class="map-chip chip-on">📍 {{ __('回報') }}</button>
            <button type="button" data-map-chip="notified" style="--chip-color:#818cf8"
                    class="map-chip chip-on">📣 {{ __('已通報') }}</button>
            <button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8"
                    class="map-chip chip-on">✅ {{ __('已排除') }}</button>
```

- [ ] **Step 4: 改 `initChips` `layerToggles`**

把 `resources/js/app.js:400-405`（`const layerToggles = { … };`）換成：

```js
    const setStatus = (key, on) => { on ? activeStatuses.add(key) : activeStatuses.delete(key); };
    const layerToggles = {
        shelters: (on) => { sheltersVisible = on; renderShelters(); },
        radar_echo: toggleRadarEcho,
        flood_potential: toggleFloodPotential,
        reported: (on) => { setStatus('reported', on); scheduleFetch(); },
        notified: (on) => { setStatus('notified', on); scheduleFetch(); },
        resolved: (on) => { showResolved = on; setStatus('resolved', on); scheduleFetch(); },
    };
```

- [ ] **Step 5: build + 測試 + 瀏覽器驗收**

```bash
npm run build
php artisan test --compact --filter=MapMarkerTest
```
Expected: 測試 PASS。chrome-devtools 驗收：關「已通報」chip → 半透明點消失；關「已排除」→ 灰階點消失；重開恢復。

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/radar.blade.php resources/js/app.js tests/Feature/MapMarkerTest.php
git commit -m "feat: v0.3 三態篩選 chip（回報/已通報/已排除），回報/已通報前端過濾（spec §12.8）"
```

---

## 驗證（Phase 3b 收尾）

- `php artisan test --compact --filter=MapMarkerTest` → 全綠。
- `npm run build` 後 chrome-devtools：pin 三態視覺正確、三態 chip 可過濾、popup 查核層級仍在。
- `grep -n circleMarker resources/js/app.js` → 無輸出（spec §12.9-7 要求 `buildPointsLayer` 不再有 circleMarker）。

## Self-Review 註記

- **Spec 覆蓋**：§12.3 pin 三態 + 查核退場（Task 1）；§12.8 三態篩選（Task 2）。
- **型別一致**：`pinIcon(meta, status)`；`activeStatuses` 元素為 `'reported'|'notified'|'resolved'`；`p.st ?? 'reported'` 對應 payload（`null`→reported）。
- **效能**：resolved 仍伺服端 gating（`show_resolved`），reported/notified 為已下發 active 點的前端過濾，切換以 `scheduleFetch` 重建，符合既有節流設計。
- **無 placeholder**：所有步驟含完整程式碼。
