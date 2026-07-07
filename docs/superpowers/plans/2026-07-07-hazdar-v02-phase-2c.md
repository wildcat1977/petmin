# Hazdar v0.2 Phase 2c — 最新消息 + 交通清運資訊彙整 + 圖卡專區 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 三個內容系統：`/news` 最新消息頁＋上稿系統（spec §11.4）、`info_links` 交通與清運資訊彙整看板取代 EOC 物資看板（§11.5）、`/cards` 圖卡專區＋首頁地圖 icon（§11.6）。

**Architecture:** news 擴充既有 `announcements` 表（跑馬燈沿用 `is_active`，news 上稿以 `published_at` 為準——一套後台餵兩處）；info_links 與 promo_cards 為新 reference 表＋Filament CRUD（結構比照 DashboardResources 三頁式）；公開頁走既有 `pages/*` + `partials/nav` 慣例，`/news` 用 closure route + 60s cache。三個 task 相互獨立。

**Tech Stack:** Laravel 13 / Livewire 4 SFC / Filament 5 / Tailwind 4 / PHPUnit 12。

## Global Constraints

- 測試 PHPUnit class；`php artisan test --compact tests/...`；commit 前 `vendor/bin/pint --dirty --format agent`。
- Commit 訊息 `feat:` 前綴＋`Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>` trailer。
- **每個 task 的 commit 後立刻跑 `composer dump-autoload -o`**（本目錄即生產環境、optimize-autoloader 開啟，新 class 不 dump 會讓 web 端 500——本 session 已發生過一次）。
- **共用快取只存純陣列/scalar，禁存 Eloquent 物件**（同一事故的教訓）。
- 新增 UI 文字用 `__('中文')`，`lang/en.json` 補對照、維持合法 JSON；`zh_TW.json` 不動。
- Filament 新 resource 結構/命名比照 `app/Filament/Resources/DashboardResources/**`（三頁式 + Schemas + Tables）；中文 modelLabel。
- 公開頁比照 `pages/contact.blade.php`：`@extends('layouts.app')` + `@include('partials.nav')`；路由用 named route。
- news 分類固定四類（spec §11.4）：`school_closure` 停班停課／`home_safety` 居家災防／`gov_press` 市府與氣象署／`incident` 災情通報。
- 免責彈窗（layouts 內建）會出現在所有新頁——正常，不要動它。
- 图卡上傳：`FileUpload` 比照 `ReportForm.php:94-100`（disk `public`、`->image()`），directory 用 `cards`；`public/storage` symlink 已存在。

---

### Task 1: 最新消息 — announcements 擴充 + `/news` 頁 + 上稿系統

**Files:**
- Create: `database/migrations/2026_07_07_000003_add_news_fields_to_announcements.php`
- Modify: `app/Models/Announcement.php`
- Modify: `app/Filament/Resources/Announcements/AnnouncementResource.php`（form 32-48、table 55-76 附近）
- Create: `resources/views/pages/news.blade.php`
- Modify: `routes/web.php`（`/news` route）
- Modify: `resources/views/partials/nav.blade.php`（桌機 20-40 與行動 59-101 兩處加「最新消息」連結）
- Modify: `resources/views/pages/radar.blade.php:83-96`（footer 加「最新消息」連結）
- Modify: `lang/en.json`
- Test: `tests/Feature/NewsPageTest.php`

**Interfaces:**
- Produces: `Announcement` 新欄位 `category`(string nullable)/`body`(text nullable)/`link_url`(string nullable)/`is_pinned`(bool)/`published_at`(timestamp nullable)；`Announcement::scopePublished($query)`；`Announcement::CATEGORY_LABELS` 常數（固定四類，非 DB 驅動——spec 即固定清單）；route `news`。
- 語義：跑馬燈（announcement-banner）**不動**，仍看 `is_active`；news 頁只看 `published_at <= now()`，置頂（`is_pinned`）優先、`published_at` 倒序；頁面 cache 60s（key `hazdar:news-page:{locale}`，存渲染資料的純陣列）。

- [ ] **Step 1: 失敗的測試**

```php
<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 最新消息頁（spec §11.4）：published_at 控制上稿、置頂優先、
 * 未發布不外洩；跑馬燈語義（is_active）不受影響。
 */
class NewsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_items_render_in_pinned_then_date_order(): void
    {
        Announcement::create(['title' => '較舊消息', 'content' => 'x', 'is_active' => false, 'category' => 'gov_press', 'published_at' => now()->subDay()]);
        Announcement::create(['title' => '置頂消息', 'content' => 'x', 'is_active' => false, 'category' => 'incident', 'is_pinned' => true, 'published_at' => now()->subDays(3)]);
        Announcement::create(['title' => '最新消息一則', 'content' => 'x', 'is_active' => false, 'category' => 'school_closure', 'published_at' => now()->subHour()]);

        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/news')->assertOk()->getContent();

        $this->assertTrue(strpos($html, '置頂消息') < strpos($html, '最新消息一則'));
        $this->assertTrue(strpos($html, '最新消息一則') < strpos($html, '較舊消息'));
        $this->assertStringContainsString('災情通報', $html);   // 分類徽章
        $this->assertStringContainsString('停班停課', $html);
    }

    public function test_unpublished_and_future_items_are_hidden(): void
    {
        Announcement::create(['title' => '草稿不可見', 'content' => 'x', 'is_active' => true]);
        Announcement::create(['title' => '未來排程不可見', 'content' => 'x', 'is_active' => false, 'published_at' => now()->addDay()]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/news')
            ->assertOk()
            ->assertDontSee('草稿不可見')
            ->assertDontSee('未來排程不可見');
    }

    public function test_banner_semantics_unchanged_by_news_fields(): void
    {
        $a = Announcement::create(['title' => 'T', 'content' => '跑馬燈內容', 'is_active' => true, 'published_at' => null]);

        $this->assertTrue(Announcement::active()->get()->contains($a));
        $this->assertFalse(Announcement::published()->get()->contains($a));
    }

    public function test_external_link_renders_when_set(): void
    {
        Announcement::create(['title' => '外連消息', 'content' => 'x', 'is_active' => false, 'category' => 'gov_press', 'link_url' => 'https://www.gov.taipei/example', 'published_at' => now()]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/news')
            ->assertSee('https://www.gov.taipei/example', false);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗** — `php artisan test --compact tests/Feature/NewsPageTest.php` → route 不存在/欄位不存在 FAIL

- [ ] **Step 3: 實作**

Migration：

```php
Schema::table('announcements', function (Blueprint $table) {
    $table->string('category', 32)->nullable()->after('content');   // 固定四類，見 Announcement::CATEGORY_LABELS
    $table->text('body')->nullable()->after('category');            // 富文本（RichEditor HTML）
    $table->string('link_url', 500)->nullable()->after('body');
    $table->boolean('is_pinned')->default(false)->after('is_active');
    $table->timestamp('published_at')->nullable()->after('is_pinned');
    $table->index(['published_at', 'is_pinned']);
});
```

Model 追加：

```php
    public const CATEGORY_LABELS = [
        'school_closure' => '停班停課',
        'home_safety' => '居家災防',
        'gov_press' => '市府與氣象署',
        'incident' => '災情通報',
    ];
```

fillable 加 `category, body, link_url, is_pinned, published_at`；casts 加 `'is_pinned' => 'boolean', 'published_at' => 'datetime'`；scope：

```php
    /** news 頁上稿判準：published_at 已到（與跑馬燈的 is_active 正交） */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
```

Route（`/reports` 之後）：

```php
// 最新消息（spec §11.4）：上稿看 published_at；cache 60s 存純陣列
Route::get('/news', function () {
    $items = Cache::remember('hazdar:news-page', 60, fn () => \App\Models\Announcement::published()
        ->orderByDesc('is_pinned')
        ->orderByDesc('published_at')
        ->get()
        ->map(fn ($a) => [
            'title' => $a->title,
            'category' => $a->category,
            'body' => $a->body,
            'content' => $a->content,
            'link_url' => $a->link_url,
            'is_pinned' => $a->is_pinned,
            'published_at' => $a->published_at->format('Y-m-d H:i'),
        ])->all());

    return view('pages.news', ['items' => $items]);
})->name('news');
```

（Cache 失效：AnnouncementResource 的 Edit/Create 頁 `afterSave`/`afterCreate` hook `Cache::forget('hazdar:news-page')`，或簡單靠 60s TTL——**採 TTL 即可**，spec 驗收是 ≤60s。）

`pages/news.blade.php`（比照 contact 頁骨架；分類徽章配色 school_closure=amber / home_safety=cyan / gov_press=sky / incident=rose；置頂項帶 📌；`body` 用 `{!! !!}` 渲染（RichEditor HTML，僅管理員可寫）、無 body 時顯示 `content`（`whitespace-pre-line` 逐字）；`link_url` 顯示為「🔗 相關連結」外開新分頁）：

```blade
@extends('layouts.app')

@section('title', '最新消息 · Hazdar 災情回報')

@section('content')
<div class="min-h-dvh bg-[#0d1117] text-slate-200">
    @include('partials.nav')
    <div class="mx-auto max-w-2xl px-4 pb-16 pt-6">
        <h1 class="text-xl font-bold text-slate-100">📰 {{ __('最新消息') }}</h1>
        <p class="mt-1 text-xs text-slate-500">{{ __('停班停課、居家災防、市府與氣象署公告、災情通報') }}</p>

        <div class="mt-6 space-y-4">
            @forelse ($items as $item)
                @php
                    $badge = match ($item['category']) {
                        'school_closure' => 'bg-amber-500/15 text-amber-300',
                        'home_safety' => 'bg-cyan-500/15 text-cyan-300',
                        'gov_press' => 'bg-sky-500/15 text-sky-300',
                        'incident' => 'bg-rose-500/15 text-rose-300',
                        default => 'bg-white/10 text-slate-300',
                    };
                @endphp
                <article class="rounded-2xl border border-white/10 bg-[#161b22] p-5 {{ $item['is_pinned'] ? 'ring-1 ring-cyan-500/40' : '' }}">
                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                        @if ($item['is_pinned'])<span>📌</span>@endif
                        @if ($item['category'])
                            <span class="rounded-full px-2 py-0.5 font-semibold {{ $badge }}">{{ __(\App\Models\Announcement::CATEGORY_LABELS[$item['category']] ?? $item['category']) }}</span>
                        @endif
                        <span class="text-slate-500">{{ $item['published_at'] }}</span>
                    </div>
                    @if ($item['title'])<h2 class="mt-2 font-bold text-slate-100">{{ $item['title'] }}</h2>@endif
                    @if ($item['body'])
                        <div class="prose prose-sm prose-invert mt-2 max-w-none text-slate-300">{!! $item['body'] !!}</div>
                    @else
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-300">{{ $item['content'] }}</p>
                    @endif
                    @if ($item['link_url'])
                        <a href="{{ $item['link_url'] }}" target="_blank" rel="noopener"
                           class="mt-3 inline-block text-xs text-cyan-400 hover:text-cyan-300">🔗 {{ __('相關連結') }} ↗</a>
                    @endif
                </article>
            @empty
                <p class="text-sm text-slate-500">{{ __('目前沒有消息。') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
```

AnnouncementResource form 追加（既有三欄後）：

```php
Select::make('category')->label('分類')->options(Announcement::CATEGORY_LABELS)->nullable(),
RichEditor::make('body')->label('內文（最新消息頁，選填；空白時顯示上方公告內容）')->columnSpanFull(),
TextInput::make('link_url')->label('相關連結')->url()->maxLength(500),
Toggle::make('is_pinned')->label('置頂'),
DateTimePicker::make('published_at')->label('發布時間（設定後出現在最新消息頁；留空＝僅跑馬燈）'),
```

Table 加 `IconColumn::make('is_pinned')->boolean()->label('置頂')` 與 `TextColumn::make('published_at')->dateTime('Y-m-d H:i')->label('發布時間')`；`navigationLabel` 改 `'公告與消息'`。

nav.blade.php 桌機/行動選單各加一條（放「通報清單」前）：`<a href="{{ route('news') }}" ...>{{ __('最新消息') }}</a>`（樣式抄同列連結）。radar footer（83-96）在「EOC 戰情室」後加 `<a href="{{ route('news') }}" class="hover:text-slate-300">{{ __('最新消息') }}</a>`。

en.json：`"最新消息": "News"`、`"相關連結": "Related Link"`、`"目前沒有消息。": "No news yet."`、`"停班停課": "School & Work Closures"`、`"居家災防": "Home Safety"`、`"市府與氣象署": "City Gov & CWA"`、`"災情通報": "Incident Updates"`、`"停班停課、居家災防、市府與氣象署公告、災情通報": "Closures, home safety, city government & CWA announcements, incident updates"`。

- [ ] **Step 4: 測試綠** — NewsPageTest 4 tests PASS；`php artisan test --compact tests/Feature/`（Announcements 既有測試不得壞）
- [ ] **Step 5: Pint + commit + dump-autoload**

```bash
vendor/bin/pint --dirty --format agent
git add -A ':(exclude)public/Hazdar-Banner.png'
git commit -m "feat: 最新消息頁 /news + 公告上稿系統擴充（spec §11.4）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
composer dump-autoload -o
```

---

### Task 2: 交通與清運資訊彙整看板（取代 EOC 物資看板）

**Files:**
- Create: `database/migrations/2026_07_07_000004_create_info_links_table.php`
- Create: `app/Models/InfoLink.php` + `database/factories/InfoLinkFactory.php`
- Create: `app/Filament/Resources/InfoLinks/**`（比照 DashboardResources 三頁式）
- Modify: `resources/views/livewire/eoc-dashboard.blade.php`（PHP `getResourcesProperty` 113-125 → `getInfoLinksProperty`；template 物資看板 section 290-311 整段替換；`use` 移除 DashboardResource、加 InfoLink/Shelter）
- Modify: `app/Filament/Resources/DashboardResources/DashboardResourceResource.php`（加 `shouldRegisterNavigation(): bool => false`，表與資料保留）
- Modify: `lang/en.json`
- Test: `tests/Feature/InfoLinksBoardTest.php`

**Interfaces:**
- Produces: `info_links` 表（`section` string／`label`／`url` 500／`status_text` nullable／`sort_order`／`is_active`）；`InfoLink::SECTION_LABELS`（transit 大眾交通／water_gate 水門與抽水站／sanitation 清運與環保局／shelter 避難收容所）；eoc-dashboard `getInfoLinksProperty(): array`（`[section => [{label,url,status_text}]]`，只含 active、依 sort_order）與 `getOpenShelterCountProperty(): int`。

**Migration 內建 seed（會議文件的官方連結，一字不改）：**

```php
DB::table('info_links')->insert([
    ['section' => 'transit', 'label' => '台北捷運', 'url' => 'https://www.metro.taipei/', 'sort_order' => 10],
    ['section' => 'transit', 'label' => '台北公車動態', 'url' => 'https://ebus.gov.taipei/', 'sort_order' => 20],
    ['section' => 'transit', 'label' => '北市 EOC 交通資訊', 'url' => 'https://eoc.gov.taipei/Page/Transport', 'sort_order' => 30],
    ['section' => 'water_gate', 'label' => '水門開放狀態', 'url' => 'https://gis.gov.taipei/ttfp', 'sort_order' => 10],
    ['section' => 'water_gate', 'label' => '抽水站資訊（data.taipei）', 'url' => 'https://data.taipei/dataset/detail?id=2bbfb30e-de58-43bd-9cc9-b56e9a6b5369', 'sort_order' => 20],
    ['section' => 'sanitation', 'label' => '清運與環保局公告', 'url' => 'https://www.dep.gov.taipei/News.aspx?n=68D9FDB562F2B943&sms=78D644F2755ACCAA', 'sort_order' => 10],
    ['section' => 'shelter', 'label' => '避難收容所開設公告', 'url' => 'https://www.doe.gov.taipei/News_Content.aspx?n=BA819C04388F2B6E&sms=69B4E6B26379EE4E&s=929AD2A327986813', 'sort_order' => 10],
]);
```

（`is_active` default true、timestamps now()——寫成完整 insert 陣列時補上。）

**卡片 template（取代 290-311）：**

```blade
<section class="flex-1 overflow-y-auto rounded-xl border border-white/10 bg-[#0f1522]/90 p-4">
    <p class="text-[10px] font-semibold tracking-widest text-slate-500">交通與清運資訊彙整</p>
    <div class="mt-2 space-y-3">
        @foreach ($this->infoLinks as $section => $links)
            <div>
                <p class="text-[11px] font-semibold text-slate-400">{{ \App\Models\InfoLink::SECTION_LABELS[$section] ?? $section }}
                    @if ($section === 'shelter')
                        <span class="ml-1 rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] text-emerald-300">開設中 {{ $this->openShelterCount }}</span>
                    @endif
                </p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($links as $link)
                        <li class="flex items-baseline justify-between gap-2 text-xs">
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="text-cyan-400 hover:text-cyan-300">{{ $link['label'] }} ↗</a>
                            @if ($link['status_text'])<span class="text-[10px] text-slate-500">{{ $link['status_text'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>
```

PHP：

```php
    /** @return array<string, list<array{label: string, url: string, status_text: ?string}>> */
    public function getInfoLinksProperty(): array
    {
        return InfoLink::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['section', 'label', 'url', 'status_text'])
            ->groupBy('section')
            ->map(fn ($g) => $g->map(fn ($l) => ['label' => $l->label, 'url' => $l->url, 'status_text' => $l->status_text])->all())
            ->all();
    }

    public function getOpenShelterCountProperty(): int
    {
        return Shelter::query()->where('is_open', true)->count();
    }
```

**Filament InfoLinks resource 規則：** `Select section`（SECTION_LABELS）、label required、url required+url rule、status_text（helper「短狀態文字，會顯示在連結旁」）、reorderable('sort_order')、is_active ToggleColumn；modelLabel 資訊連結、navigationLabel 資訊彙整看板。

**Test（`tests/Feature/InfoLinksBoardTest.php`）：**

```php
public function test_dashboard_shows_info_links_grouped_with_shelter_count(): void
{
    \App\Models\Shelter::factory()->count(2)->create(['is_open' => true]);
    \App\Models\Shelter::factory()->create(['is_open' => false]);

    Livewire::test('eoc-dashboard')
        ->assertSee('交通與清運資訊彙整')
        ->assertSee('台北捷運')
        ->assertSee('水門開放狀態')
        ->assertSee('開設中 2')
        ->assertDontSee('物資 / 民力調度看板');
}

public function test_inactive_link_is_hidden(): void
{
    \App\Models\InfoLink::where('label', '台北捷運')->first()->update(['is_active' => false]);

    Livewire::test('eoc-dashboard')->assertDontSee('台北捷運');
}
```

（Shelter factory 是否存在先查 `database/factories/`；無則測試改直接 `Shelter::create` 帶必要欄位——讀 model fillable。）

- [ ] Steps: 測試先紅 → 實作 → `tests/Feature/InfoLinksBoardTest.php` + `tests/Feature/EocDashboardTest.php` + `tests/Feature/Filament/` 綠 → 全套綠 → Pint → commit（`feat: 交通與清運資訊彙整看板取代物資看板（spec §11.5）`+trailer）→ `composer dump-autoload -o`

---

### Task 3: 圖卡專區 `/cards` + 首頁地圖 icon

**Files:**
- Create: `database/migrations/2026_07_07_000005_create_promo_cards_table.php`
- Create: `app/Models/PromoCard.php` + `database/factories/PromoCardFactory.php`
- Create: `app/Filament/Resources/PromoCards/**`（三頁式）
- Create: `resources/views/pages/cards.blade.php`
- Modify: `routes/web.php`（`/cards` route）
- Modify: `resources/views/pages/radar.blade.php`（header 右側 icon 群 21-36 加 `<a>` 連 /cards；footer 83-96 加「圖卡專區」）
- Modify: `lang/en.json`
- Test: `tests/Feature/PromoCardsPageTest.php`

**Interfaces:**
- Produces: `promo_cards` 表（`title`／`image_path`／`group` string：`shen`｜`councilor`／`sort_order`／`is_active`）；`PromoCard::GROUP_LABELS = ['shen' => '沈伯洋圖卡', 'councilor' => '議員候選人圖卡']`；route `cards`。
- 注意 `group` 是 SQL 保留字——query 一律經 Eloquent（自動 quote），不手寫 raw SQL。

**Migration：**

```php
Schema::create('promo_cards', function (Blueprint $table) {
    $table->id();
    $table->string('title', 100);
    $table->string('image_path', 500);
    $table->string('group', 32);              // shen | councilor（PromoCard::GROUP_LABELS）
    $table->smallInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**`/cards` route：**

```php
// 圖卡專區（spec §11.6）
Route::get('/cards', function () {
    $groups = \App\Models\PromoCard::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get()
        ->groupBy('group');

    return view('pages.cards', ['groups' => $groups]);
})->name('cards');
```

**`pages/cards.blade.php`：** nav 骨架同 news；依 `PromoCard::GROUP_LABELS` 順序兩區塊；grid `grid-cols-2 sm:grid-cols-3 gap-3`；每張卡 `<button>` 開 Alpine lightbox（`x-data="{ open: null }"` 放外層，點卡 `@click="open = '{{ Storage::disk('public')->url($card->image_path) }}'"`，lightbox 固定全螢幕 `x-show="open"` 顯示大圖＋`<a :href="open" download>⬇ {{ __('下載') }}</a>`＋點背景關閉）；卡片 `<img loading="lazy">`＋title 下標。空群組顯示「圖卡即將上線」。

**radar.blade.php header icon（登入區塊前）：**

```blade
<a href="{{ route('cards') }}"
   class="flex items-center gap-1 rounded-full bg-black/60 px-3 py-2 text-xs text-slate-200 backdrop-blur hover:bg-black/80">
    🖼️ <span class="hidden sm:inline">{{ __('圖卡專區') }}</span>
</a>
```

footer 加 `<a href="{{ route('cards') }}" class="hover:text-slate-300">{{ __('圖卡專區') }}</a>`。

**Filament PromoCards resource 規則：** `FileUpload::make('image_path')->disk('public')->directory('cards')->image()->required()`（比照 ReportForm 寫法）、title required、`Select group`（GROUP_LABELS）required、reorderable('sort_order')、is_active ToggleColumn、table 用 `ImageColumn::make('image_path')->disk('public')`；modelLabel 圖卡。

**Test：**

```php
public function test_active_cards_render_grouped(): void
{
    \App\Models\PromoCard::create(['title' => '防災三步驟', 'image_path' => 'cards/a.png', 'group' => 'shen', 'is_active' => true]);
    \App\Models\PromoCard::create(['title' => '里長聯絡卡', 'image_path' => 'cards/b.png', 'group' => 'councilor', 'is_active' => true]);
    \App\Models\PromoCard::create(['title' => '下架卡', 'image_path' => 'cards/c.png', 'group' => 'shen', 'is_active' => false]);

    $this->withHeader('Accept-Language', 'zh-TW')->get('/cards')
        ->assertOk()
        ->assertSee('沈伯洋圖卡')->assertSee('議員候選人圖卡')
        ->assertSee('防災三步驟')->assertSee('里長聯絡卡')
        ->assertDontSee('下架卡');
}

public function test_home_page_links_to_cards(): void
{
    $this->withHeader('Accept-Language', 'zh-TW')->get('/')
        ->assertSee(route('cards'), false)
        ->assertSee('圖卡專區');
}
```

en.json：`"圖卡專區": "Info Cards"`、`"下載": "Download"`、`"圖卡即將上線": "Cards coming soon"`、`"沈伯洋圖卡": "Puma Shen's Cards"`、`"議員候選人圖卡": "Council Candidates' Cards"`。

- [ ] Steps: 測試先紅 → 實作 → 目標測試綠 → 全套綠 → Pint → commit（`feat: 圖卡專區 /cards + 首頁地圖 icon（spec §11.6）`+trailer）→ `composer dump-autoload -o`

---

### Task 4: Phase 2c 收尾

- [ ] `php artisan migrate --force`（線上）＋確認 `/news`、`/cards` HTTP 200、EOC 卡片顯示資訊彙整。
- [ ] `php artisan test --compact` 全綠；`npm run build`（本 phase 無 JS 變更，保險）。
- [ ] 瀏覽器目測三頁 + Filament 三個後台（Announcements 擴充欄位、InfoLinks、PromoCards）。
- [ ] Ledger checkpoint + final review（whole-branch）。
