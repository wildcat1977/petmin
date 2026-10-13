# 本地開發

## 一鍵啟動

```bash
composer dev
```

`composer.json` 內建腳本會用 [concurrently](https://www.npmjs.com/package/concurrently) 同時跑：

- `php artisan serve` — Laravel server
- `php artisan queue:listen` — Queue worker（AI 審核 / 佐證判定）
- `php artisan pail` — 即時 log 監看
- `npm run dev` — Vite HMR

## 個別命令

```bash
php artisan serve              # 8000 port
npm run dev                    # Vite
php artisan horizon            # Production-like queue dashboard
php artisan migrate:fresh --seed  # 重建 + open data 匯入 + 示範回報（僅 local 產生）
```

`DemoSeeder` 會先跑所有 open data 匯入指令（清潔箱、狗便袋箱、公車站牌、狗活動區），
再灑約 100 筆掛在桶上的回報、「這裡需要桶」回報、一個佐證群（同桶 2 帳號 → corroborated），
最後 `demand:recompute` 產生需求分數。
> 注意：`DatabaseSeeder` **不可**用 `WithoutModelEvents`——`geohash7` 推導與佐證判定都掛在
> Report model events 上，關掉就不會觸發。跑 seed 後記得讓 queue worker 消化佐證 job。

## 撰寫 Livewire 4 組件（SFC）

前台頁面使用 single-file component 寫法，PHP class 與 Blade 同檔：

```
resources/views/livewire/{name}.blade.php
```

```php
<?php
use Livewire\Component;

new class extends Component
{
    public string $msg = 'Hi';
}; ?>

<div>{{ $msg }}</div>
```

引用：`<livewire:name />`

## 測試

```bash
php artisan test --compact
# 針對單一檔案
php artisan test --compact tests/Feature/CorroborationTest.php
```

測試 DB 為 sqlite `:memory:`（`phpunit.xml`）。重點測試：

| 檔案 | 涵蓋 |
| ---- | ---- |
| `tests/Unit/GeohashTest.php` | geohash 編碼參考向量、網格覆蓋 |
| `tests/Feature/CorroborationTest.php` | 佐證升級（3 帳號/6h）、同帳號洗版不算、窗外不算、official 不被觸碰 |
| `tests/Feature/Api/MapPointsApiTest.php` | bbox 過濾、高低 zoom 模式、照片僅審核後公開、筆數上限 |
| `tests/Feature/ReportSubmissionTest.php` | 寫入即回應、cooldown/時限、滿溢程度僅 full |
| `tests/Feature/ModerateReportImageJobTest.php` | 明確違規退件、不吻合分類留人工、API fail-open |
| `tests/Feature/Commands/*` | open data 匯入（冪等、壞列跳過、狀態不覆寫）、OD 權重、失敗不默默跳過 |
| `tests/Feature/Services/*` | 需求分數權重／上限／扣分、有袋沒桶 |
| `tests/Feature/EocDashboardTest.php` | 統計定義、外部中斷降級面板、footer 真實健康 |

## 程式碼風格

```bash
php vendor/bin/pint --dirty --format agent
```

> 本環境 `vendor/bin/pint` 可能無執行權限，請以 `php vendor/bin/pint` 呼叫。

## Tip：手機實機測試

```bash
php artisan serve --host=0.0.0.0
npm run dev -- --host
```

`.env` 設 `APP_URL=http://192.168.x.x:8000`。地理位置 API 需要 HTTPS 或 `localhost`；
實機測試可用 `mkcert` 做本地憑證，或 `ngrok http 8000` 取得 https URL。

## 除錯：外部資料源

各 fetch command 可獨立執行觀察落地結果與健康燈：

```bash
php artisan petmin:fetch-ncdr-alerts   # 落地 external_alerts，印出 new/total
php artisan tinker --execute 'print_r(app(App\Services\DataSourceHealth::class)->all());'
```

## 除錯：用 Chrome DevTools MCP 驅動瀏覽器截圖

前端／版面／RWD 問題（配色、溢出、跑馬燈、JS 未解譯直接 print、Alpine/Livewire 互動）
**不必請使用者手動截圖回傳**——可透過 `chrome-devtools` MCP 直接驅動瀏覽器導頁、模擬手機、
截圖、讀 console，把「看得到」的線索自己抓進來再改。

### 前置

- **先直接試 MCP**（`list_pages`）。多數情況下 MCP 會自己啟動一顆 Chrome（走
  `--remote-debugging-pipe`，非 TCP port；即使無 X server 也常能跑起來），截圖／snapshot 都正常。
- ⚠️ **但這台無 X server，MCP 自啟瀏覽器「時好時壞」**：實測過同一環境有時第一次呼叫直接噴
  `Missing X server to start the headful browser`。遇到這個錯，用下面的**手動起 headless
  Chrome** 當可靠退路——本專案 MCP 也支援連既有瀏覽器（找 port `9222`）：

  ```bash
  google-chrome --headless=new --disable-gpu --no-sandbox \
    --remote-debugging-port=9222 \
    --user-data-dir=/tmp/chrome-mcp about:blank &
  ```

  起好後 `list_pages` 應能看到 `about:blank`，代表已連上。用完
  `pkill -f "remote-debugging-port=9222"` 收掉。（MCP 自啟的那顆走 pipe、9222 不會有回應，
  但 `pkill -x chrome` 可整組收掉。）
- 若 `list_pages` / `new_page` / `navigate_page` 仍失敗，跑 troubleshooting skill。
- 備援：完全不想碰 MCP，可用手動 headless Chrome + Node 內建 `WebSocket` 直接打
  CDP（`fetch http://127.0.0.1:9222/json/new`（**PUT**）拿分頁 → `Page.captureScreenshot`
  截圖 / `Runtime.evaluate` 跑 `document.elementFromPoint()` 做精確斷言），不經 MCP 也能截圖除錯。
- 導頁目標用**跑得起來的網址**：本地 `php artisan serve`（見上方 `get-absolute-url` 拿正確
  scheme/port），或線上 `https://petmin.puma.taipei`。多數公開頁免登入即可重現。

### 核心工具序列

| 工具 | 用途 |
| ---- | ---- |
| `list_pages` / `new_page` / `select_page` | 取得或開一個分頁 |
| `navigate_page` | 導到目標 URL（改完 blade 記得先 `view:clear` 再導，避免吃舊編譯快取） |
| `emulate` / `resize_page` | 模擬手機視窗（重現「小高度手機」類 RWD bug 的關鍵） |
| `take_snapshot` | 取 DOM/a11y 樹（純文字、比截圖省，先用它定位元素 uid 再操作） |
| `take_screenshot` | 視覺截圖（驗配色/版面；可 `fullPage`） |
| `list_console_messages` | 抓 JS 錯誤與警告（`@json` 未跳脫引號洩漏、Alpine 解析失敗都在這） |
| `click` / `fill` | 關免責彈窗、登入、展開回報表單等互動 |
| `evaluate_script` | 讀 computed style / CSS 變數 / 元素尺寸做精確斷言 |

### 本專案常用配方

- **重現「手機高度不足、回報表單無法上下滑」**：`emulate` 成矮視窗（如 iPhone SE 375×667）
  → `click` 開回報表單 → `take_screenshot` 看 modal 是否被裁切、能否滾動（對照
  `max-h-[90dvh] overflow-y-auto` 是否生效）。
- **驗證主題配色改動（`PETMIN_COLOR_BG/CARD`）**：`navigate_page` →
  `evaluate_script` 讀 `getComputedStyle(document.body).backgroundColor` 或
  `getComputedStyle(document.documentElement).getPropertyValue('--color-radar-bg')`
  確認注入值；再 `take_screenshot` 目視。（空白/透明底通常是 `.env` 的 `#` 值沒加引號被
  當註解，見 CLAUDE.md 主題色那條。）
- **抓「JS 未解譯直接 print」類 bug（統計/透明度頁曾中招）**：`navigate_page` 該頁 →
  `take_screenshot`（會看到原始 JS 文字）＋ `list_console_messages`（看 SyntaxError）。
  根因多為 `@json`（options=0 不跳脫引號，破壞 HTML 屬性）→ 改用 `{{ Js::from(...) }}`。
- **驗證跑馬燈/動畫**：`take_screenshot` 間隔兩次比對位移，或 `evaluate_script` 讀
  元素 `getBoundingClientRect()`。

### 注意

- **免責彈窗**每 session 跳一次，會蓋住內容——先 `take_snapshot` 找關閉鈕 `click` 掉再截圖。
- **`emulate` 切手機視窗後地圖 tile 可能空白**（只剩六角網格底）：Leaflet 在模擬 viewport
  下重抓 tile 有時機差，headless 截圖那一刻常還沒載完，**不是 bug**（真實手機正常）。要驗地圖
  本體再 `navigate_page` reload + `wait_for` 關鍵字後再截；驗 footer/RWD 版面則不受影響。
- **後台 `/admin` 需 Google/LINE 登入**，無法 headless 完成 OAuth；驗證以**公開頁**為主
  （`/ /reports /news /cards /city-info /contact`）。
- 截圖只證明「當下畫面」，仍以 `php artisan test` 為行為正確性的最終依據（RWD/視覺類
  bug 尤其要人眼＋截圖，測試補不齊）。
