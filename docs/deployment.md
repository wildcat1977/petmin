# 部署 & 第三方設定

## 系統需求

- PHP 8.4+（已測 PHP 8.5）
- Composer 2.x
- Node.js 20+
- Redis 6+
- PostgreSQL 15+（本專案以 PostgreSQL 為前提；geohash 分桶 + CHECK constraint enum）
- 必要 PHP extensions: `pdo_pgsql`, `mbstring`, `gd`/`imagick`, `redis`, `intl`, `bcmath`, `simplexml`

## 環境變數重點

```dotenv
APP_URL=https://hazdar.example.com   # 必須含 https，影響 OAuth redirect

DB_CONNECTION=pgsql
DB_DATABASE=civic_shield
DB_USERNAME=...
DB_PASSWORD=...

REDIS_HOST=127.0.0.1
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# 認證（災時主入口預期是 LINE）
GOOGLE_CLIENT_ID=...          # Google Cloud Console → OAuth 2.0 Client (Web)
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
LINE_CLIENT_ID=...            # LINE Developers → LINE Login Channel
LINE_CLIENT_SECRET=...
LINE_REDIRECT_URI="${APP_URL}/auth/line/callback"
LIFF_ID=...                   # LINE LIFF App ID

# AI 照片審核（未填時 fail-open，照片留待人工）
CLAUDE_API_KEY=...

# 外部資料源
CWA_API_KEY=...               # opendata.cwa.gov.tw 授權碼（未填時該源亮紅燈，不影響回報）
TAIPEI_SHELTER_RESOURCE_ID=...# data.taipei 收容所 dataset rid（未填則不同步）
# 淹水潛勢圖層（擇一；此圖資無公開即插即用圖磚，需自 TGOS/WRA 取得後填入）
FLOOD_POTENTIAL_XYZ_URL=...    # XYZ 圖磚模板，含 {z}/{x}/{y}
FLOOD_POTENTIAL_WMS_URL=...    # 或標準 WMS base URL
FLOOD_POTENTIAL_WMS_LAYERS=... # 搭配 WMS 的 layer 名稱
```

> **注意**：fork 自 ratdar，若沿用既有 Google/LINE OAuth app，需在各自 console 為新網域補上
> redirect URI 才能登入。

## Socialite Provider 設定

### Google
1. 前往 <https://console.cloud.google.com/apis/credentials>
2. 建立 OAuth 2.0 Client ID（Web application）
3. Authorized redirect URI 填入 `${APP_URL}/auth/google/callback`

### LINE
1. 前往 <https://developers.line.biz/console/>
2. 建立 LINE Login Channel，Callback URL 填入 `${APP_URL}/auth/line/callback`
3. 啟用 `profile` scope；若要 email 需開啟 `openid email` 並送審
4. LIFF：新增 LIFF app 取得 `LIFF_ID`，Endpoint URL 指向 `${APP_URL}`

## 部署步驟

```bash
git clone <repo> civic_shield && cd civic_shield
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env
php artisan key:generate
php artisan migrate --force
php artisan storage:link
npm ci && npm run build

# Filament admin
php artisan make:filament-user

# Cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
```

### 排程（Laravel Scheduler）

外部資料整合 command 由 `routes/console.php` 排程（CWA/NCDR 5min、雷達 10min、收容所 daily）。
需在 crontab 掛上 Laravel scheduler：

```cron
* * * * * cd /var/www/html/civic_shield && php artisan schedule:run >> /dev/null 2>&1
```

各 command 亦可獨立手動執行（除錯用）：

```bash
php artisan hazdar:fetch-cwa-observations
php artisan hazdar:fetch-ncdr-alerts
php artisan hazdar:fetch-radar-echo
php artisan hazdar:sync-shelters
```

### Horizon (Supervisor)

處理 AI 照片審核（`ModerateReportImage`）與佐證判定（`EvaluateCorroboration`）queue：

```ini
[program:hazdar-horizon]
process_name=%(program_name)s
command=php /var/www/html/civic_shield/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/hazdar-horizon.log
stopwaitsecs=3600
```

### Nginx 範例（主要路徑）

```nginx
server {
    listen 443 ssl http2;
    server_name hazdar.example.com;
    root /var/www/html/civic_shield/public;

    index index.php;
    client_max_body_size 24M;   # 允許照片上傳（Livewire 規則上限 20MB）

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

## Filament Admin 訪問控制

沿用 ratdar：`User` 以 `is_admin` 旗標 + `PROTECTED_ADMIN_EMAILS` 設定控制後台存取。
公開唯讀的 EOC dashboard（`/dashboard`）與民眾地圖無權限概念，MVP 不做角色系統（spec §7.3）。

## 韌性與安全注意事項

- **回報寫入與外部整合零共用依賴**：外部 fetch 全掛時整站仍可回報、可看已落地的圖（spec §8）
- 每支外部 fetch 有 10s timeout 與獨立 try/catch；失敗記 log + 健康狀態降級，不 retry storm
- `image_path` 來自 `storage/app/public`，僅在 `status = approved` 時經 `publicImageUrl()` 輸出
- 上傳檔案類型由 Livewire `image` 規則檢查，最大 20MB
- 反濫用：帳號封禁 + cooldown（預設 60s）+ 每帳號每小時上限（預設 10）
- Socialite redirect 受 `whereIn('provider', [...])` 限制；`(provider, provider_id)` 唯一鍵防帳戶綁架
- 雷達回波經後端 proxy（`/api/radar-echo`）供圖，避免 CORS 與打爆來源
