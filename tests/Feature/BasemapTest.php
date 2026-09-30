<?php

namespace Tests\Feature;

use App\Filament\Resources\Reports\Pages\EditReport;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 底圖改自架 Protomaps（docs/basemap.md）：MapTiler 條款禁止代理與伺服器端快取，且原本把 API key
 * 印在網頁原始碼；統計、透明度頁與後台則直連 tile.openstreetmap.org，會被 OSM 以不符使用政策擋成 403。
 * 所有地圖改用 tiles.puma.taipei 的 light / dark 兩種 style。
 */
class BasemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_gets_both_basemap_styles_and_no_maptiler(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // @js 把陣列輸出成 JSON.parse('…')，斜線多層跳脫，分段斷言
        $this->assertStringContainsString('window.__HAZDAR_BASEMAP_CONFIG__', $html);
        $this->assertStringContainsString('tiles.puma.taipei', $html);
        $this->assertStringContainsString('light.json', $html);
        $this->assertStringContainsString('dark.json', $html);
        // 不可再出現 MapTiler（也就不會印出 key）
        $this->assertStringNotContainsStringIgnoringCase('maptiler', $html);
    }

    public function test_radar_band_credits_protomaps_and_openstreetmap(): void
    {
        // 主地圖關掉 Leaflet 內建 attribution（會被 UI 蓋住），版權改放在保證可見的底帶；ODbL 要求標示出處
        $this->get('/')->assertOk()->assertSee('Protomaps', false)->assertSee('OpenStreetMap', false);
    }

    /**
     * 沒有 WebGL 的瀏覽器畫不出向量底圖；不退回 OSM 點陣圖磚（OSM 擋無 Referer 的請求），
     * 改顯示一行提示、點位照常——提示元素要在頁面上等 JS 取消隱藏。
     */
    public function test_radar_has_a_hidden_notice_for_browsers_without_webgl(): void
    {
        $this->get('/')->assertOk()->assertSee('data-basemap-unsupported', false);
    }

    public function test_radar_connects_to_the_tile_host_before_the_app_script_runs(): void
    {
        // 底圖要等 app.js 下載、執行完才開始抓 style → 圖示 → 圖磚，一路串起來先灰一兩秒（洋流好店 2026-10-01）
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="preconnect" href="https://tiles.puma.taipei" crossorigin>', $html);
        $this->assertLessThan(strpos($html, '/build/assets/app-'), strpos($html, 'rel="preconnect" href="https://tiles.puma.taipei'));
    }

    /** 統計、透明度頁原本直連 OSM 並以 CSS 反色做暗色，改用共用的自架底圖（暗色） */
    public function test_stats_and_transparency_maps_use_the_shared_dark_basemap(): void
    {
        // 這兩頁的統計查詢用 PostgreSQL 的 DATE_TRUNC，sqlite 測試環境無法整頁渲染，改檢查 view 原始碼
        foreach (['stats', 'transparency'] as $page) {
            $source = file_get_contents(resource_path("views/pages/{$page}.blade.php"));

            $this->assertStringNotContainsString('tile.openstreetmap.org', $source, $page);
            $this->assertStringNotContainsString('invert(100%)', $source, $page);
            $this->assertStringContainsString("__HAZDAR_BASEMAP__?.create('dark')", $source, $page);
        }
    }

    /** 通報列表的地圖彈窗原本走 MapTiler 並以 CSS 反色做暗色，改用共用的自架底圖（暗色） */
    public function test_report_list_modal_map_uses_the_shared_dark_basemap(): void
    {
        Report::factory()->create(['status' => Report::STATUS_APPROVED]);

        $this->get('/reports')
            ->assertOk()
            ->assertDontSee('tile.openstreetmap.org', false)
            ->assertDontSee('darkFilter', false)
            ->assertSee("__HAZDAR_BASEMAP__?.create('dark')", false);
    }

    public function test_admin_map_picker_uses_the_self_hosted_basemap_with_the_frontend_maplibre_version(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $report = Report::factory()->create();

        // 後台用 CDN <script> 載 UMD 檔；maplibre-gl 6.x 只出 ESM，dist/maplibre-gl.js 不存在
        // （洋流好店 2026-10-01 差點上線一個 404 的後台地圖）。版本必須跟前台 npm 相同且有 UMD 檔；
        // 以實際安裝的版本為準：package.json 若寫成範圍，npm update 會讓前台跑到別的版本
        $version = json_decode(file_get_contents(base_path('node_modules/maplibre-gl/package.json')), true)['version'];
        $this->assertSame($version, json_decode(file_get_contents(base_path('package.json')), true)['dependencies']['maplibre-gl'], 'package.json 必須釘死版本');
        $this->assertFileExists(base_path('node_modules/maplibre-gl/dist/maplibre-gl.js'));
        // 外掛同理：前台 npm 與後台 CDN 必須同版
        $plugin = json_decode(file_get_contents(base_path('node_modules/@maplibre/maplibre-gl-leaflet/package.json')), true)['version'];
        $this->assertSame($plugin, json_decode(file_get_contents(base_path('package.json')), true)['dependencies']['@maplibre/maplibre-gl-leaflet'], 'package.json 必須釘死版本');

        // CDN <script> 以 @push 推進 layout 的 stack，Livewire::test 看不到，要整頁請求
        $this->get(EditReport::getUrl(['record' => $report]))
            ->assertOk()
            ->assertSee("maplibre-gl@{$version}/dist/maplibre-gl.js", false)
            ->assertSee("maplibre-gl-leaflet@{$plugin}/leaflet-maplibre-gl.js", false)
            ->assertSee('L.maplibreGL', false)
            ->assertDontSee('tile.openstreetmap.org', false);
    }

    /**
     * 回報表單關閉時 Livewire 以 @if 移除整個表單，下次開啟是新元素、新地圖；舊地圖若沒 remove()，
     * 每開一次就多留一個 WebGL context，颱風天連續回報累積超過瀏覽器上限時會擠掉主地圖的底圖
     * （2026-10-01 審查發現；瀏覽器實測修正前 3 次開關釋放 0 個、修正後 3 個）。JS 無測試環境，檢查接線。
     */
    public function test_report_form_pin_map_is_removed_when_the_form_closes(): void
    {
        $form = file_get_contents(resource_path('views/livewire/report-form.blade.php'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('destroy() { this.$el.__pinMap?.remove();', $form);
        $this->assertStringContainsString('el.__pinMap = map;', $app);
    }
}
