import L from 'leaflet';
import { geocoder as createGeocoder, geocoders } from 'leaflet-control-geocoder';
import exifr from 'exifr';
import {
    Chart,
    ArcElement, Tooltip, Legend, PieController,
    CategoryScale, LinearScale, BarElement, BarController,
} from 'chart.js';

Chart.register(
    ArcElement, Tooltip, Legend, PieController,
    CategoryScale, LinearScale, BarElement, BarController,
);
window.Chart = Chart;

// 讓 Alpine x-init inline 可以直接用 window.L
window.L = L;

/**
 * 從選取的 File 物件解析 EXIF GPS 座標。
 * 回傳 { lat, lng } 或 null（無 GPS 資訊 / 解析失敗）。
 */
async function readExifLocation(file) {
    try {
        const gps = await exifr.gps(file);
        if (gps && gps.latitude != null && gps.longitude != null) {
            return { lat: gps.latitude, lng: gps.longitude };
        }
    } catch (_) { /* 靜默忽略解析錯誤 */ }
    return null;
}

/**
 * 用 canvas 將圖片縮小至長邊 ≤ 1024px，品質 0.72，回傳 File 物件。
 * 如果原圖已經夠小就直接回傳原 File。
 */
async function compressImage(file, maxEdge = 1024, quality = 0.72) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            const { naturalWidth: w, naturalHeight: h } = img;
            if (w <= maxEdge && h <= maxEdge) { resolve(file); return; }

            const scale  = maxEdge / Math.max(w, h);
            const canvas = document.createElement('canvas');
            canvas.width  = Math.round(w * scale);
            canvas.height = Math.round(h * scale);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

            canvas.toBlob((blob) => {
                resolve(new File([blob], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' }));
            }, 'image/jpeg', quality);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

/**
 * 全域供 blade 的 @change handler 呼叫。
 * 1. canvas 壓縮（長邊 ≤ 1024px）
 * 2. 解析 EXIF GPS
 * 3. 把壓縮後的 File 回填到 input，Livewire 再讀取就拿到小檔
 */
window.onPhotoChange = async function (input, wire) {
    const file = input.files?.[0];
    if (!file) return;

    // 壓縮
    const compressed = await compressImage(file);

    // 把壓縮後的檔案回填到 input（觸發 Livewire wire:model 重新讀取）
    if (compressed !== file) {
        const dt = new DataTransfer();
        dt.items.add(compressed);
        input.files = dt.files;
        // 手動觸發 livewire 的 input event 讓 wire:model 更新
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // EXIF GPS（從原始檔讀，壓縮後 EXIF 已遺失）
    const loc = await readExifLocation(file);
    if (!loc) return;

    window.dispatchEvent(new CustomEvent('exif-location', { detail: loc }));
    wire.setLocationFromExif(loc.lat, loc.lng);
};

const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const MAP_THEME_KEY = 'civicshield_map_theme';
const TILE_OPTIONS = {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    subdomains: 'abc',
    maxZoom: 19,
};

// 暗色模式用 CSS filter 實現（免 API key）
function applyTileDarkFilter(theme) {
    if (!radarMap) return;
    const pane = radarMap.getPane('tilePane');
    if (pane) {
        pane.style.filter = theme === 'dark'
            ? 'invert(100%) hue-rotate(180deg) brightness(0.85) contrast(1.05)'
            : '';
    }
}

// ── 災情分類視覺（對應圖例：淹水藍/道路橘/停電黃/結構紅/收容所綠）──
const CATEGORY_META = {
    flood:             { color: '#38bdf8', label: () => window.i18n?.catFlood ?? '淹水' },
    road_blocked:      { color: '#fb923c', label: () => window.i18n?.catRoadBlocked ?? '道路中斷' },
    power_outage:      { color: '#facc15', label: () => window.i18n?.catPowerOutage ?? '停電' },
    structural_damage: { color: '#f87171', label: () => window.i18n?.catStructural ?? '結構受損' },
};

// 視覺分層：unverified 半透明 → corroborated 實心 → official 加白框
const VERIFICATION_STYLE = {
    unverified:   { fillOpacity: 0.4,  weight: 1,   ring: false },
    corroborated: { fillOpacity: 0.9,  weight: 1.5, ring: false },
    official:     { fillOpacity: 0.95, weight: 3,   ring: true  },
};

const VERIFICATION_LABEL = {
    unverified:   () => window.i18n?.vlUnverified ?? '未核實',
    corroborated: () => window.i18n?.vlCorroborated ?? '多方佐證',
    official:     () => window.i18n?.vlOfficial ?? '官方核實',
};

let radarMap = null;
let reportsLayer = null;       // 目前顯示的點位/聚合圖層
let sheltersLayer = null;      // 收容所圖層
let currentTileLayer = null;   // 底圖圖層（隨主題切換）
let activeCategories = new Set(Object.keys(CATEGORY_META));
let sheltersVisible = false;
let sheltersCache = null;      // 收容所清單快取（每次頁面生命週期抓一次）
let fetchController = null;
let fetchTimer = null;
// 讀 localStorage，預設 dark（災防情境的品牌主題）
let currentTheme = (() => { try { return localStorage.getItem(MAP_THEME_KEY) || 'dark'; } catch (_) { return 'dark'; } })();

// ── 跑馬燈廣播（從 #radar-map data-ticker 讀取真實資料）───────
function initTicker() {
    const mapEl = document.getElementById('radar-map');
    const messages = mapEl ? JSON.parse(mapEl.dataset.ticker || '[]') : [];
    const tickerEl = document.getElementById('radar-ticker-text');
    if (!tickerEl || !messages.length) return;

    let idx = 0;
    tickerEl.textContent = messages[0];
    setInterval(() => {
        tickerEl.style.opacity = '0';
        setTimeout(() => {
            idx = (idx + 1) % messages.length;
            tickerEl.textContent = messages[idx];
            tickerEl.style.opacity = '1';
        }, 400);
    }, 7000);
}

function createPopupEl(p) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:260px;font-size:13px;line-height:1.5';

    if (p.img) {
        const img = document.createElement('img');
        img.src = p.img;
        img.alt = window.i18n?.reportPhoto ?? '回報照片';
        img.style.cssText = 'width:100%;height:180px;object-fit:cover;border-radius:6px;margin-bottom:8px;display:block;cursor:pointer';
        img.addEventListener('click', () => window.open(p.img, '_blank'));
        wrap.appendChild(img);
    }

    const meta = CATEGORY_META[p.cat];
    const head = document.createElement('div');
    head.style.cssText = 'margin-bottom:4px;font-weight:600;font-size:12px;display:flex;align-items:center;gap:6px';
    const dot = document.createElement('span');
    dot.style.cssText = `width:10px;height:10px;border-radius:50%;background:${meta?.color ?? '#94a3b8'};display:inline-block`;
    head.appendChild(dot);
    const title = document.createElement('span');
    let text = meta ? meta.label() : p.cat;
    if (p.cat === 'flood' && p.depth) text += `（${window.i18n?.waterDepth ?? '水深'} ~${p.depth}cm）`;
    title.textContent = text;
    head.appendChild(title);
    const vl = document.createElement('span');
    vl.style.cssText = 'margin-left:auto;font-weight:500;font-size:11px;color:#64748b';
    vl.textContent = VERIFICATION_LABEL[p.vl]?.() ?? '';
    head.appendChild(vl);
    wrap.appendChild(head);

    if (p.desc) {
        const desc = document.createElement('p');
        desc.style.cssText = 'margin:0 0 4px;color:#1e293b';
        desc.textContent = p.desc;
        wrap.appendChild(desc);
    }
    const small = document.createElement('small');
    small.style.color = '#64748b';
    small.textContent = p.at ?? '';
    wrap.appendChild(small);

    return wrap;
}

function buildPointsLayer(points) {
    const layer = L.layerGroup();
    points.forEach((p) => {
        const meta = CATEGORY_META[p.cat];
        const style = VERIFICATION_STYLE[p.vl] ?? VERIFICATION_STYLE.unverified;
        const circle = L.circleMarker([p.lat, p.lng], {
            radius: 8,
            fillColor: meta?.color ?? '#94a3b8',
            color: style.ring ? '#ffffff' : (meta?.color ?? '#94a3b8'),
            weight: style.weight,
            opacity: 1,
            fillOpacity: style.fillOpacity,
        });
        circle.bindPopup(createPopupEl(p), { maxWidth: 280 });
        layer.addLayer(circle);
    });
    return layer;
}

function buildClustersLayer(clusters) {
    const layer = L.layerGroup();
    clusters.forEach((c) => {
        const meta = CATEGORY_META[c.dominant_category];
        const color = meta?.color ?? '#94a3b8';
        const size = c.count < 10 ? 34 : c.count < 100 ? 42 : 50;
        const icon = L.divIcon({
            html: `<div style="width:${size}px;height:${size}px;background:${color}cc;border:2px solid #fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#0f172a;box-shadow:0 0 10px ${color}88">${c.count}</div>`,
            className: '',
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
        });
        const marker = L.marker([c.lat, c.lng], { icon });
        marker.on('click', () => {
            radarMap.setView([c.lat, c.lng], Math.min(radarMap.getZoom() + 2, 18));
        });
        layer.addLayer(marker);
    });
    return layer;
}

function renderReports(payload) {
    if (!radarMap) return;
    const layer = payload.mode === 'points'
        ? buildPointsLayer(payload.points ?? [])
        : buildClustersLayer(payload.clusters ?? []);

    if (reportsLayer) radarMap.removeLayer(reportsLayer);
    reportsLayer = layer.addTo(radarMap);
}

function scheduleFetch() {
    clearTimeout(fetchTimer);
    fetchTimer = setTimeout(fetchReports, 250);
}

async function fetchReports() {
    if (!radarMap) return;
    const el = document.getElementById('radar-map');
    if (!el) return;

    if (activeCategories.size === 0) {
        if (reportsLayer) { radarMap.removeLayer(reportsLayer); reportsLayer = null; }
        return;
    }

    const b = radarMap.getBounds();
    const params = new URLSearchParams({
        bbox: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map((v) => v.toFixed(5)).join(','),
        zoom: radarMap.getZoom(),
        categories: [...activeCategories].join(','),
    });

    if (fetchController) fetchController.abort();
    fetchController = new AbortController();

    try {
        const res = await fetch(`${el.dataset.reportsUrl}?${params}`, {
            signal: fetchController.signal,
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        renderReports(await res.json());
    } catch (_) { /* abort 或離線：保留現有圖層 */ }
}

// ── 收容所圖層（綠）──────────────────────────────────────────
async function renderShelters() {
    if (!radarMap) return;
    if (!sheltersVisible) {
        if (sheltersLayer) { radarMap.removeLayer(sheltersLayer); sheltersLayer = null; }
        return;
    }

    if (!sheltersCache) {
        const el = document.getElementById('radar-map');
        try {
            const res = await fetch(el.dataset.sheltersUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            sheltersCache = await res.json();
        } catch (_) { return; }
    }

    const layer = L.layerGroup();
    sheltersCache.forEach((s) => {
        const icon = L.divIcon({
            html: `<div style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:15px;background:#4ade80;border:2px solid #fff;border-radius:50%;box-shadow:0 0 10px rgba(74,222,128,0.7);opacity:${s.open ? 1 : 0.45}">🏠</div>`,
            className: '',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
        });
        const marker = L.marker([s.lat, s.lng], { icon });
        const openLabel = s.open
            ? (window.i18n?.shelterOpen ?? '🟢 開設中')
            : (window.i18n?.shelterClosed ?? '⚪ 未開設');
        const capacity = s.capacity ? `｜${window.i18n?.shelterCapacity ?? '容量'} ${s.capacity}` : '';
        // 名稱/地址為外部同步資料，用 textContent 組裝避免 XSS
        const popup = document.createElement('div');
        popup.style.cssText = 'font-size:13px;line-height:1.5';
        const nameEl = document.createElement('b');
        nameEl.textContent = s.name;
        const statusEl = document.createElement('div');
        statusEl.textContent = openLabel + capacity;
        const addrEl = document.createElement('small');
        addrEl.style.color = '#64748b';
        addrEl.textContent = s.address ?? '';
        popup.append(nameEl, statusEl, addrEl);
        marker.bindPopup(popup, { maxWidth: 260 });
        layer.addLayer(marker);
    });

    if (sheltersLayer) radarMap.removeLayer(sheltersLayer);
    sheltersLayer = layer.addTo(radarMap);
}

// ── 分類篩選 chips（radar 頁 header 渲染，data-map-chip 綁定）──
function initChips() {
    document.querySelectorAll('[data-map-chip]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const cat = btn.dataset.mapChip;
            if (cat === 'shelters') {
                sheltersVisible = ! sheltersVisible;
                btn.classList.toggle('chip-on', sheltersVisible);
                renderShelters();
                return;
            }
            if (activeCategories.has(cat)) {
                activeCategories.delete(cat);
            } else {
                activeCategories.add(cat);
            }
            btn.classList.toggle('chip-on', activeCategories.has(cat));
            scheduleFetch();
        });
    });
}

// ── 地圖主題切換 ──────────────────────────────────────────────
function setMapTheme(theme) {
    currentTheme = theme;
    try { localStorage.setItem(MAP_THEME_KEY, theme); } catch (_) {}
    const mapEl = document.getElementById('radar-map');
    if (mapEl) mapEl.classList.toggle('map-theme-dark', theme === 'dark');
    if (radarMap && currentTileLayer) {
        applyTileDarkFilter(theme);
    }
}

const ThemeToggleControl = L.Control.extend({
    options: { position: 'topleft' },
    onAdd() {
        const btn = L.DomUtil.create('button', 'leaflet-theme-toggle');
        btn.type = 'button';
        const refresh = () => {
            btn.textContent = currentTheme === 'dark' ? '☀️ 亮色模式' : '🌙 暗色模式';
            btn.title = currentTheme === 'dark' ? '切換至亮色地圖' : '切換至暗色地圖';
        };
        refresh();
        L.DomEvent.disableClickPropagation(btn);
        btn.addEventListener('click', () => {
            setMapTheme(currentTheme === 'dark' ? 'light' : 'dark');
            refresh();
        });
        return btn;
    },
});

// ── 主地圖 ──────────────────────────────────────────────────
function initRadar() {
    const el = document.getElementById('radar-map');
    if (!el || radarMap) return;

    const lat = parseFloat(el.dataset.defaultLat);
    const lng = parseFloat(el.dataset.defaultLng);
    const zoom = parseInt(el.dataset.defaultZoom, 10);

    el.classList.toggle('map-theme-dark', currentTheme === 'dark');
    radarMap = L.map(el, { zoomControl: false, attributionControl: false }).setView([lat, lng], zoom);

    currentTileLayer = L.tileLayer(TILE_URL, TILE_OPTIONS).addTo(radarMap);
    // dark filter 需在 map 初始化後再套用
    radarMap.whenReady(() => applyTileDarkFilter(currentTheme));
    L.control.zoom({ position: 'topright' }).addTo(radarMap);

    radarMap.on('moveend', scheduleFetch);
    radarMap.on('zoomend', scheduleFetch);
    fetchReports();

    new ThemeToggleControl().addTo(radarMap);

    // 地標搜尋（訪客可用，左上縮合圖示，展開後搜尋）
    createGeocoder({
        defaultMarkGeocode: false,
        collapsed: true,
        placeholder: window.i18n?.searchPlaceholderRadar ?? '搜尋地標或路名…(尚不支援詳細地址)',
        position: 'topleft',
        geocoder: geocoders.nominatim({
            serviceUrl: 'https://nominatim.openstreetmap.org/',
            geocodingQueryParams: { countrycodes: 'tw', limit: 5 },
        }),
    })
    .on('markgeocode', (e) => {
        radarMap.setView(e.geocode.center, 15);
    })
    .addTo(radarMap);

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                radarMap.setView([pos.coords.latitude, pos.coords.longitude], 16);
            },
            () => {},
            { enableHighAccuracy: true, timeout: 8000 }
        );
    }

    initChips();
    initTicker();
}

// ── 回報表單小地圖（由 Alpine x-init 呼叫）──────────────────
/**
 * @param {HTMLElement} el   - 地圖容器
 * @param {object}      wire - Alpine $wire proxy（對應 ReportForm Livewire 元件）
 */
window.initPinMap = function (el, wire) {
    const DEFAULT_LAT = 25.033;
    const DEFAULT_LNG = 121.5654;
    let skipNextMoveEnd = false;

    const map = L.map(el, { zoomControl: true, attributionControl: false })
        .setView([DEFAULT_LAT, DEFAULT_LNG], 17);

    L.tileLayer(TILE_URL, TILE_OPTIONS).addTo(map);

    // 地址搜尋（Nominatim / OSM，免費無 API Key）
    createGeocoder({
        defaultMarkGeocode: false,
        collapsed: true,
        placeholder: window.i18n?.searchPlaceholderPin ?? '搜尋地標或路名…(暫不支援門牌號碼)',
        geocoder: geocoders.nominatim({ serviceUrl: 'https://nominatim.openstreetmap.org/', geocodingQueryParams: { countrycodes: 'tw', limit: 5 } }),
    })
    .on('markgeocode', (e) => {
        const { lat, lng } = e.geocode.center;
        setPos(lat, lng);
    })
    .addTo(map);

    // 📍 取得目前位置按鈕
    const LocateControl = L.Control.extend({
        options: { position: 'bottomright' },
        onAdd() {
            const btn = L.DomUtil.create('button', '');
            btn.innerHTML = window.i18n?.myLocation ?? '📍 我的位置';
            btn.title = window.i18n?.getGPS ?? '取得目前 GPS 位置';
            btn.style.cssText = 'padding:5px 10px;font-size:12px;cursor:pointer;background:#fff;color:#1e293b;border:1px solid rgba(0,0,0,0.2);border-radius:6px;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,0.12);';
            L.DomEvent.on(btn, 'click', (ev) => {
                L.DomEvent.stopPropagation(ev);
                if (!navigator.geolocation) return;
                btn.textContent = window.i18n?.locating ?? '定位中…';
                btn.disabled = true;
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        setPos(pos.coords.latitude, pos.coords.longitude);
                        btn.innerHTML = window.i18n?.myLocation ?? '📍 我的位置';
                        btn.disabled = false;
                    },
                    () => {
                        btn.innerHTML = window.i18n?.myLocation ?? '📍 我的位置';
                        btn.disabled = false;
                    },
                    { enableHighAccuracy: true, timeout: 8000 }
                );
            });
            return btn;
        },
    });
    new LocateControl().addTo(map);

    // 中心固定圖釘（取代可拖曳 marker）— 地圖中心即回報座標
    const pinEl = document.createElement('div');
    pinEl.style.cssText = 'position:absolute;left:50%;top:50%;transform:translate(-50%,-100%);pointer-events:none;z-index:800;font-size:28px;line-height:1;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.5));';
    pinEl.textContent = '📍';
    el.appendChild(pinEl);

    const reverseGeocode = (lat, lng) => {
        fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=zh-TW`)
            .then(r => r.json())
            .then(data => {
                const a = data.address || {};
                const city     = a.city || a.county || a.state || '';
                const district = a.city_district || a.suburb || a.town || a.village || '';
                const parts    = [city, district].filter(Boolean);
                wire.setAddress(parts.join(' '));
            })
            .catch(() => {});
    };

    // 地圖移動結束 → 以中心點更新回報座標（拖曳地圖 = 調整位置）
    map.on('moveend', () => {
        if (skipNextMoveEnd) { skipNextMoveEnd = false; return; }
        const { lat, lng } = map.getCenter();
        wire.setLocation(lat, lng);
        reverseGeocode(lat, lng);
    });

    const setPos = (lat, lng) => {
        skipNextMoveEnd = true;
        map.setView([lat, lng], 18);
        wire.setLocation(lat, lng);
        reverseGeocode(lat, lng);
    };

    // 接收 EXIF 解析成功後的位置事件
    const onExifLocation = (e) => setPos(e.detail.lat, e.detail.lng);
    window.addEventListener('exif-location', onExifLocation);
    // 元件移除時清理事件
    el.__exifCleanup = () => window.removeEventListener('exif-location', onExifLocation);

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => setPos(pos.coords.latitude, pos.coords.longitude),
            () => setPos(DEFAULT_LAT, DEFAULT_LNG),
            { enableHighAccuracy: true, timeout: 8000 }
        );
    } else {
        setPos(DEFAULT_LAT, DEFAULT_LNG);
    }
};

// ── 事件綁定 ──────────────────────────────────────────────
/**
 * LIFF 自動登入：若在 LINE 內建瀏覽器，直接走 LIFF 授權，
 * 取得 access token 後轉到後端 LINE OAuth callback。
 * 若 LIFF SDK 未載入（外部瀏覽器）則略過，維持現有的 Google / LINE 網頁登入。
 */
async function initLiff() {
    const liffId = window.__LIFF_ID__;
    if (!liffId || typeof liff === 'undefined') return;

    // 已有 Laravel session → 不需要重新走 LIFF 登入流程
    if (window.__AUTHED__) return;

    try {
        await liff.init({ liffId });
    } catch (_) { return; }

    // 只在 LINE 內建瀏覽器中自動觸發登入
    if (!liff.isInClient()) {
        // 使用者在 LINE 內建瀏覽器直接輸入網址（非透過 liff.line.me）
        // → 重導至 LIFF URL，讓 LINE 完成 LIFF 初始化後才回來
        if (/Line\//i.test(navigator.userAgent)) {
            window.location.replace('https://liff.line.me/' + liffId);
        }
        return;
    }
    if (!liff.isLoggedIn()) {
        liff.login({ redirectUri: window.location.href });
        return;
    }

    // 已登入：把 LINE access token（及 id_token）帶到後端換 Laravel session
    const token   = liff.getAccessToken();
    const idToken = liff.getIDToken(); // 需要 openid scope 才有值，否則為 null
    const params  = new URLSearchParams(window.location.search);
    // 僅在尚未登入 Laravel 時才導向（避免無窮迴圈）
    if (!params.has('liff_authed')) {
        let callbackUrl = '/auth/line/liff-callback?access_token=' + encodeURIComponent(token);
        if (idToken) callbackUrl += '&id_token=' + encodeURIComponent(idToken);
        callbackUrl += '&redirect=' + encodeURIComponent(window.location.pathname);
        window.location.href = callbackUrl;
    }
}

document.addEventListener('livewire:initialized', () => {
    initLiff();
    initRadar();

    window.addEventListener('civicshield:report-clicked', () => {
        window.Livewire.dispatch('open-report-form');
    });

    // 回報送出或後端通知資料更新 → 重新向 bbox API 取點位
    window.addEventListener('civicshield:reports-updated', () => {
        scheduleFetch();
    });
});
