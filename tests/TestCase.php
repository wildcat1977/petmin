<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 這台是正式機：有 config 快取時 phpunit.xml 的環境變數全部失效，測試會吃正式站設定、
     * 連正式資料庫與 Redis（2026-10-02 洋流好店實際發生：RefreshDatabase 因 production 確認而沒清庫，
     * 但測試仍在正式庫上跑、把測試工作丟進正式佇列）。開機前就擋，連 RefreshDatabase 都不給跑。
     */
    protected function setUp(): void
    {
        if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('偵測到 config 快取（bootstrap/cache/config.php），測試會連到正式資料庫。先跑 php artisan config:clear 再測。');
        }

        parent::setUp();

        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new RuntimeException('測試環境設定異常（非 testing 或非 sqlite），為避免碰到正式資料已中止。');
        }

        // 語系依瀏覽器 Accept-Language 決定（SetLocale），測試請求預設帶 en-us 會拿到英文版；
        // 主要使用者是中文瀏覽器，測試照中文跑。要測英文版的個別測試自己覆寫這個 header。
        $this->withHeader('Accept-Language', 'zh-TW,zh;q=0.9');
    }
}
