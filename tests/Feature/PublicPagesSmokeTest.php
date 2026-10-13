<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 公開頁面 render 冒煙測試：抓 blade 編譯/render 期錯誤而非只看路由存在。
 *
 * 起因：report-list.blade.php 在 @php 區塊內誤用 {{-- --}} blade 註解，
 * 新版 Blade 不再於 @php 內剝除該註解 → 編譯後為非法 PHP（unexpected identifier）。
 * 舊的已編譯 view 快取遮蔽了它，直到 view:clear 重編譯才在線上爆 500。
 * 既有測試從未 render /reports，故漏網——此測試補上該保護。
 */
class PublicPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 僅列可在測試 DB（SQLite）render 的公開頁。
     * /transparency 與 /stats 的 route closure 用 PostgreSQL 專屬 SQL（DATE_TRUNC），
     * SQLite 無此函式故無法在此冒煙——線上（PostgreSQL）另行驗證。
     *
     * @return list<array{string}>
     */
    public static function publicRoutes(): array
    {
        return [
            ['/'],
            ['/reports'],
            ['/news'],
            ['/contact'],
            ['/candidates'],
            ['/leaderboard'],
        ];
    }

    #[DataProvider('publicRoutes')]
    public function test_public_page_renders_without_error(string $path): void
    {
        $this->get($path)->assertOk();
    }
}
