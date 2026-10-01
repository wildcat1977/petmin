import L from 'leaflet';
import { geocoder as createGeocoder, geocoders } from 'leaflet-control-geocoder';
import exifr from 'exifr';
import { basemapMaxZoom, createBasemapLayer, setBasemapTheme } from './basemap.js';
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
 * 2. 以 $wire.upload 上傳壓縮後的檔案（只傳一次）
 * 3. 解析 EXIF GPS
 *
 * input 刻意不掛 wire:model：wire:model 在 change 當下就上傳原圖，而 Livewire 只聽 change、
 * 壓縮後補發的 input 事件它不理，結果一直上傳手機原圖（2026-10-01 審查發現）。
 * 改送 change 也不行：原圖上傳已在跑，兩次上傳競爭，後完成的那份會蓋掉前一份。
 */
window.onPhotoChange = async function (input, wire) {
    const file = input.files?.[0];
    if (!file) return;

    const compressed = await compressImage(file);
    wire.upload('photo', compressed, () => {}, () => {});

    // EXIF GPS（從原始檔讀，壓縮後 EXIF 已遺失）
    const loc = await readExifLocation(file);
    if (!loc) return;

    window.dispatchEvent(new CustomEvent('exif-location', { detail: loc }));
    wire.setLocationFromExif(loc.lat, loc.lng);
};

const MAP_THEME_KEY = 'hazdar_map_theme';

// ── 災情分類視覺：由後台 report_categories 驅動（spec §11.7）──
const CATEGORY_META = {};
{
    const el = document.getElementById('radar-map');
    (JSON.parse(el?.dataset.categories || '[]')).forEach((c) => {
        CATEGORY_META[c.slug] = { color: c.color, emoji: c.emoji, label: () => c.name, active: c.active, extra: c.extra };
    });
}

const VERIFICATION_LABEL = {
    unverified:   () => window.i18n?.vlUnverified ?? '未核實',
    corroborated: () => window.i18n?.vlCorroborated ?? '多方佐證',
    official:     () => window.i18n?.vlOfficial ?? '官方核實',
};

let radarMap = null;
let reportsLayer = null;       // 目前顯示的點位/聚合圖層
let sheltersLayer = null;      // 收容所圖層
let radarEchoLayer = null;     // 雷達回波 ImageOverlay
let radarEchoTimer = null;
let floodLayer = null;         // 淹水潛勢 WMTS
let currentTileLayer = null;   // 底圖圖層（MapLibre；隨主題換 style，沒有 WebGL 時為 null）
// 含停用分類：停用只關新回報入口，既有點位不得消失（spec §11.7）
let activeCategories = new Set(Object.keys(CATEGORY_META));
let showResolved = true;
// 回報三態篩選（spec §12.8）：reported/notified 純前端過濾已下發點；
// resolved 另由 showResolved 伺服端 gating（隱藏時根本不下發已排除點）。
let activeStatuses = new Set(['reported', 'notified', 'resolved']);
let sheltersVisible = false;
let sheltersCache = null;      // 收容所清單快取（每次頁面生命週期抓一次）
let fetchController = null;
let fetchTimer = null;
// 讀 localStorage，預設 dark（災防情境的品牌主題）
let currentTheme = (() => { try { return localStorage.getItem(MAP_THEME_KEY) || 'dark'; } catch (_) { return 'dark'; } })();

// ── 跑馬燈廣播（從 #radar-map data-ticker 讀取真實資料）───────
// 跑馬燈（v0.3 三次回饋）：短訊息置中靜態顯示；量測到訊息寬度超出容器時，
// 改左滾動跑馬燈完整播出全文，而非用 truncate 把後半段文字直接裁掉看不到。
function initTicker() {
    const tickerSrc = document.getElementById('site-ticker');
    const messages = tickerSrc ? JSON.parse(tickerSrc.dataset.ticker || '[]') : [];
    const tickerEl = document.getElementById('radar-ticker-text');
    const wrapEl = tickerEl ? tickerEl.parentElement : null;
    if (!tickerEl || !wrapEl || !messages.length) return;

    let idx = 0;

    // 手機肉眼可讀的固定捲動速度（px/s）；越小越慢。duration 依實際捲動距離換算，
    // 讓長短訊息都以同樣速度前進（v0.3 五次回饋 item 3：原本速度過快無法閱讀）。
    const MARQUEE_SPEED = 60;

    function showNext() {
        tickerEl.classList.remove('radar-ticker-marquee');
        tickerEl.style.removeProperty('--marquee-duration');
        tickerEl.style.removeProperty('--marquee-start');
        tickerEl.style.opacity = '0';

        setTimeout(() => {
            tickerEl.textContent = messages[idx];
            tickerEl.style.opacity = '1';

            requestAnimationFrame(() => {
                const overflowPx = tickerEl.scrollWidth - wrapEl.clientWidth;
                let holdMs = 7000;
                if (overflowPx > 4) {
                    // 文字自 wrapper 右緣進、至左緣完全離開的總距離
                    const wrapW = wrapEl.clientWidth;
                    const travel = wrapW + tickerEl.scrollWidth;
                    const duration = Math.max(8, travel / MARQUEE_SPEED);
                    tickerEl.style.setProperty('--marquee-start', wrapW + 'px');
                    tickerEl.style.setProperty('--marquee-duration', duration + 's');
                    tickerEl.classList.add('radar-ticker-marquee');
                    holdMs = duration * 1000 + 600; // 跑完整輪再停留片刻才換下一則
                }
                idx = (idx + 1) % messages.length;
                setTimeout(showNext, holdMs);
            });
        }, 400);
    }

    showNext();
}

function createPopupEl(p) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:260px;font-size:13px;line-height:1.5';

    if (p.img) {
        const img = document.createElement('img');
        // 彈窗用縮圖（點位 API 的 img_md，還沒產生時退回原圖）；點開看原圖
        img.src = p.img_md ?? p.img;
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
    if (p.extra != null && meta?.extra) text += `（${meta.extra.label} ~${p.extra}${meta.extra.unit}）`;
    title.textContent = text;
    head.appendChild(title);
    const vl = document.createElement('span');
    vl.style.cssText = 'margin-left:auto;font-weight:500;font-size:11px;color:#64748b';
    vl.textContent = VERIFICATION_LABEL[p.vl]?.() ?? '';
    head.appendChild(vl);
    if (p.st) {
        const st = document.createElement('span');
        st.style.cssText = 'margin-left:6px;font-weight:600;font-size:11px;color:' + (p.st === 'resolved' ? '#94a3b8' : '#22d3ee');
        st.textContent = p.st === 'resolved' ? (window.i18n?.stResolved ?? '✅ 已排除') : (window.i18n?.stNotified ?? '📣 已通報');
        head.appendChild(st);
    }
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

// 分類色水滴 pin（spec §12.3；PM 回饋：20% 太淡改 60%）：中置分類 emoji；三態 reported=實色 / notified=60% / resolved=灰階
function pinIcon(meta, status) {
    const color = meta?.color ?? '#94a3b8';
    const emoji = meta?.emoji ?? '📍';
    const opacity = status === 'notified' ? 0.6 : 1;
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
    if (! showResolved) params.set('show_resolved', '0');

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
            html: `<div style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:15px;background:#4ade80;border:2px solid #fff;border-radius:50%;box-shadow:0 0 10px rgba(74,222,128,0.7);opacity:${s.open ? 1 : 0.6}">🏠</div>`,
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

// ── 雷達回波 overlay（後端 proxy 圖檔；開啟時每 10 分鐘換新圖）────
function toggleRadarEcho(on) {
    const el = document.getElementById('radar-map');
    if (!radarMap || !el) return;

    if (!on) {
        if (radarEchoLayer) { radarMap.removeLayer(radarEchoLayer); radarEchoLayer = null; }
        if (radarEchoTimer) { clearInterval(radarEchoTimer); radarEchoTimer = null; }
        return;
    }

    const bounds = JSON.parse(el.dataset.radarEchoBounds || '[[17.75,115.0],[29.25,126.5]]');
    const freshUrl = () => `${el.dataset.radarEchoUrl}?t=${Date.now()}`;
    radarEchoLayer = L.imageOverlay(freshUrl(), bounds, { opacity: 0.6 }).addTo(radarMap);
    radarEchoTimer = setInterval(() => radarEchoLayer?.setUrl(freshUrl()), 10 * 60 * 1000);
}

// ── 淹水潛勢圖層（支援 XYZ 圖磚或標準 WMS；未設定則 chip 不渲染）──
function toggleFloodPotential(on) {
    const el = document.getElementById('radar-map');
    if (!radarMap || !el?.dataset.floodPotential) return;

    if (!on) {
        if (floodLayer) { radarMap.removeLayer(floodLayer); floodLayer = null; }
        return;
    }

    let cfg;
    try { cfg = JSON.parse(el.dataset.floodPotential); } catch (_) { return; }

    if (cfg.xyz) {
        floodLayer = L.tileLayer(cfg.xyz, { opacity: 0.5, maxZoom: 19 }).addTo(radarMap);
    } else if (cfg.wms_url && cfg.wms_layers) {
        floodLayer = L.tileLayer.wms(cfg.wms_url, {
            layers: cfg.wms_layers,
            format: 'image/png',
            transparent: true,
            opacity: 0.5,
        }).addTo(radarMap);
    }
}

// ── 分類篩選 chips（radar 頁 header 渲染，data-map-chip 綁定）──
function initChips() {
    const setStatus = (key, on) => { on ? activeStatuses.add(key) : activeStatuses.delete(key); };
    const layerToggles = {
        shelters: (on) => { sheltersVisible = on; renderShelters(); },
        radar_echo: toggleRadarEcho,
        flood_potential: toggleFloodPotential,
        reported: (on) => { setStatus('reported', on); scheduleFetch(); },
        notified: (on) => { setStatus('notified', on); scheduleFetch(); },
        resolved: (on) => { showResolved = on; setStatus('resolved', on); scheduleFetch(); },
    };

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
}

// ── 地圖主題切換 ──────────────────────────────────────────────
function setMapTheme(theme) {
    currentTheme = theme;
    try { localStorage.setItem(MAP_THEME_KEY, theme); } catch (_) {}
    const mapEl = document.getElementById('radar-map');
    if (mapEl) mapEl.classList.toggle('map-theme-dark', theme === 'dark');
    setBasemapTheme(currentTileLayer, theme);
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
    // 主地圖是全螢幕 z-0，疊在上面的 UI 是 z-[500]，Leaflet 內建 attribution 疊在地圖容器
    // 內部會被底帶蓋住看不到——故關閉內建 attribution，改由 pages/radar.blade.php 的底帶
    // 統一顯示 logo + 版權文字（保證可見）。
    radarMap = L.map(el, { zoomControl: false, attributionControl: false, maxZoom: basemapMaxZoom() }).setView([lat, lng], zoom);

    // 沒有 WebGL 或 MapLibre 建構失敗：不畫底圖、顯示提示，點位與圖層照常
    try {
        currentTileLayer = createBasemapLayer(currentTheme);
        currentTileLayer?.addTo(radarMap);
    } catch (error) {
        currentTileLayer = null;
        console.error('basemap failed', error);
    }
    if (!currentTileLayer) {
        document.querySelector('[data-basemap-unsupported]')?.classList.remove('hidden');
    }
    L.control.zoom({ position: 'topright' }).addTo(radarMap);

    radarMap.on('moveend', scheduleFetch);
    radarMap.on('zoomend', scheduleFetch);
    fetchReports();

    // 行政區界疊層（EOC dashboard 用；data-districts-url 存在才載入）
    if (el.dataset.districtsUrl) {
        fetch(el.dataset.districtsUrl)
            .then((r) => (r.ok ? r.json() : null))
            .then((geojson) => {
                if (!geojson || !radarMap) return;
                L.geoJSON(geojson, {
                    interactive: false,
                    style: { color: '#38bdf8', weight: 1, opacity: 0.35, fillColor: '#38bdf8', fillOpacity: 0.04, dashArray: '4 3' },
                }).addTo(radarMap);
            })
            .catch(() => {});
    }

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

    const map = L.map(el, { zoomControl: true, attributionControl: true, maxZoom: basemapMaxZoom() })
        .setView([DEFAULT_LAT, DEFAULT_LNG], 17);

    // 亮色底圖；沒有 WebGL 時略過，地址搜尋、定位與 EXIF 仍可放釘
    try {
        createBasemapLayer('light')?.addTo(map);
    } catch (error) {
        console.error('basemap failed', error);
    }

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
    // 表單關閉時 Livewire 以 @if 移除整個 DOM，下次開啟是新元素、新地圖；舊地圖要由 Alpine destroy() 移除，
    // 否則每開一次表單就多留一個 WebGL context，累積超過瀏覽器上限時會擠掉主地圖的底圖
    el.__pinMap = map;

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
    // v0.3 三次回饋：跑馬燈現在是全站共用 head nav 的一部分（partials/site-header.blade.php），
    // 獨立於地圖之外呼叫，次要頁面（沒有 #radar-map、initRadar() 提早 return）才會跑得到。
    initTicker();

    window.addEventListener('hazdar:report-clicked', () => {
        window.Livewire.dispatch('open-report-form');
    });

    // 回報送出或後端通知資料更新 → 重新向 bbox API 取點位
    window.addEventListener('hazdar:reports-updated', () => {
        scheduleFetch();
    });
});
