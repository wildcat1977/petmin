import L from './leaflet-global.js';
import 'leaflet.markercluster';
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

    // 壓縮＋上傳期間通知表單停用送出（report-form 的 photoBusy）
    const busy = (on) => window.dispatchEvent(new CustomEvent('photo-busy', { detail: on }));
    busy(true);
    const compressed = await compressImage(file);
    wire.upload('photo', compressed, () => busy(false), () => busy(false));

    // EXIF GPS（從原始檔讀，壓縮後 EXIF 已遺失）
    const loc = await readExifLocation(file);
    if (!loc) return;

    window.dispatchEvent(new CustomEvent('exif-location', { detail: loc }));
    wire.setLocationFromExif(loc.lat, loc.lng);
};

const MAP_THEME_KEY = 'petmin_map_theme';

// ── 回報分類視覺：由後台 report_categories 驅動 ──
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
let reportsLayer = null;       // 目前顯示的回報點位/聚合圖層
let binsLayer = null;          // 桶位圖層（清潔箱 / 狗便袋箱 / 已確認消失的桶，前端 markercluster）
let gapsLayer = null;          // 有袋沒桶圖層（一次全撈）
let demandLayer = null;        // 需求色塊（DemandGrid 方格，一次全撈）
let currentTileLayer = null;   // 底圖圖層（MapLibre；隨主題換 style，沒有 WebGL 時為 null）
// 含停用分類：停用只關新回報入口，既有點位不得消失（spec §11.7）
let activeCategories = new Set(Object.keys(CATEGORY_META));
let showResolved = true;
// 回報三態篩選（spec §12.8）：reported/notified 純前端過濾已下發點；
// resolved 另由 showResolved 伺服端 gating（隱藏時根本不下發已排除點）。
let activeStatuses = new Set(['reported', 'notified', 'resolved']);
// 桶位圖層開關：kinds（清潔箱 / 狗便袋箱）+ 是否顯示已確認消失的桶
const binLayers = { bins: true, bag_boxes: true, missing_bins: false, occupied_only: false };
let gapsVisible = false;
let demandVisible = true;      // 提案核心產出：一進站就顯示
let gapsCache = null;
let demandCache = null;
let fetchController = null;
let binsFetchController = null;
let fetchTimer = null;
// 讀 localStorage，預設 light（白天遛狗情境）
let currentTheme = (() => { try { return localStorage.getItem(MAP_THEME_KEY) || 'light'; } catch (_) { return 'light'; } })();

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
        st.textContent = p.st === 'resolved' ? (window.i18n?.stResolved ?? '✅ 已處理') : (window.i18n?.stNotified ?? '📣 已通報');
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

// 分類色水滴 pin：中置分類 emoji；三態 reported=實色 / notified=60% / resolved=灰階
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

// ── 桶位圖層（bbox API；高 zoom 個別桶、低 zoom 聚合）──────────
const BIN_STYLE = {
    clean_box:   { color: '#22c55e', emoji: '🗑️', label: () => window.i18n?.binCleanBox ?? '行人專用清潔箱' },
    dog_bag_box: { color: '#38bdf8', emoji: '🐕', label: () => window.i18n?.binBagBox ?? '狗便清潔袋箱' },
};
const BIN_STATUS_LABEL = {
    active:     () => window.i18n?.binActive ?? '使用中',
    missing:    () => window.i18n?.binMissing ?? '消失／已撤',
    unverified: () => window.i18n?.binUnverified ?? '待驗證',
};

function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function binIcon(b) {
    const style = BIN_STYLE[b.kind] ?? BIN_STYLE.clean_box;
    const missing = b.status === 'missing';
    // 被佔領命名的桶放大、金框、插大一點的旗，一眼就分得出來
    const size = b.nick ? 36 : 26;
    const html =
        `<div style="width:${size}px;height:${size}px;display:flex;align-items:center;justify-content:center;font-size:${b.nick ? 19 : 14}px;` +
        `background:${missing ? '#a78bfa' : style.color};border:${b.nick ? '3px solid #f59e0b' : '2px solid #fff'};border-radius:${b.nick ? 10 : 8}px;` +
        `box-shadow:${b.nick ? '0 0 0 2px #fff,0 3px 10px rgba(0,0,0,0.45)' : '0 2px 6px rgba(0,0,0,0.4)'};opacity:${missing ? 0.75 : 1};${missing ? 'filter:grayscale(0.4)' : ''}">` +
        `${missing ? '👻' : style.emoji}</div>` +
        (b.nick ? '<div style="position:absolute;top:-14px;right:-12px;font-size:20px;line-height:1;filter:drop-shadow(0 1px 1px rgba(0,0,0,.5))">🚩</div>' : '') +
        // 名字標在圖示下方（像 IITC 的 portal 名稱）；名字是市民取的，進 HTML 前要跳脫
        (b.nick ? `<div class="bin-nick-label" style="top:${size + 3}px">${escapeHtml(b.nick)}</div>` : '');
    return L.divIcon({ html: `<div style="position:relative">${html}</div>`, className: '', iconSize: [size, size], iconAnchor: [size / 2, size / 2], popupAnchor: [0, -(size / 2 + 1)] });
}

function createBinPopupEl(b) {
    const style = BIN_STYLE[b.kind] ?? BIN_STYLE.clean_box;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:240px;font-size:13px;line-height:1.5;color:#1e293b';
    const head = document.createElement('div');
    head.style.cssText = 'font-weight:600;display:flex;align-items:center;gap:6px';
    head.textContent = `${style.emoji} ${style.label()}`;
    const st = document.createElement('span');
    st.style.cssText = `margin-left:auto;font-size:11px;font-weight:500;color:${b.status === 'missing' ? '#7c3aed' : b.status === 'active' ? '#15803d' : '#b45309'}`;
    st.textContent = BIN_STATUS_LABEL[b.status]?.() ?? b.status;
    head.appendChild(st);
    wrap.appendChild(head);
    // 佔領命名：市民取的名字，同樣只用 textContent
    if (b.nick) {
        const nick = document.createElement('div');
        nick.style.cssText = 'margin-top:2px;font-size:15px;font-weight:800;color:#b45309;word-break:break-all';
        nick.textContent = `🚩「${b.nick}」`;
        const owner = document.createElement('small');
        owner.style.cssText = 'display:block;color:#64748b';
        owner.textContent = `${window.i18n?.binOwnedBy ?? '佔領者'}：${b.owner ?? (window.i18n?.binAnonymousOwner ?? '匿名巡桶員')}`;
        wrap.append(nick, owner);
    }
    // 名稱/地址為 open data，用 textContent 組裝避免 XSS
    const name = document.createElement('div');
    name.textContent = [b.district, b.name].filter(Boolean).join(' ') + (b.n > 1 ? `（${b.n} 處）` : '');
    wrap.appendChild(name);
    const meta = document.createElement('small');
    meta.style.color = '#64748b';
    meta.textContent = (b.verified_at ? `${window.i18n?.binVerified ?? '最近驗證'} ${b.verified_at}` : (window.i18n?.binNeverVerified ?? '尚未有人驗證'))
        + (b.reports ? `｜${b.reports} ${window.i18n?.binReports ?? '筆回報'}` : '');
    wrap.appendChild(meta);
    // 官方清單移除＝「消失的桶」的官方證據
    if (b.removed_at) {
        const removed = document.createElement('div');
        removed.style.cssText = 'margin-top:4px;font-size:12px;font-weight:600;color:#7c3aed';
        removed.textContent = `${window.i18n?.binRemovedFromList ?? '環保局清單移除於'} ${b.removed_at}`;
        wrap.appendChild(removed);
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = window.i18n?.binReportThis ?? '🐾 回報這個桶';
    btn.style.cssText = 'margin-top:8px;width:100%;padding:7px 10px;border:none;border-radius:8px;background:#f59e0b;color:#1c1917;font-weight:700;cursor:pointer';
    btn.addEventListener('click', () => {
        radarMap?.closePopup();
        window.Livewire.dispatch('open-report-form', { binId: b.id });
    });
    wrap.appendChild(btn);
    const occupation = document.createElement('a');
    occupation.href = `/b/${encodeURIComponent(b.code)}`;
    occupation.textContent = window.i18n?.binOccupation ?? '🏴 佔領榜／幫桶命名';
    occupation.style.cssText = 'display:block;margin-top:6px;text-align:center;font-size:12px;color:#0369a1;text-decoration:underline';
    wrap.appendChild(occupation);
    return wrap;
}

// 桶位在前端依畫面像素距離聚合：40px 內的桶才合併，聚合點一定落在它代表的桶旁邊。
// （伺服器端以 geohash 大格子聚合時，重心會落在需求方格上沒有桶的地方，跟方格對不起來。）
// 滑過聚合點畫出涵蓋範圍；聚合色取裡面最多的桶種。
let binsCache = { key: null, points: [] };

function binClusterIcon(cluster) {
    const counts = {};
    cluster.getAllChildMarkers().forEach((m) => { counts[m.options.binKind] = (counts[m.options.binKind] ?? 0) + 1; });
    const kind = Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0];
    const color = BIN_STYLE[kind]?.color ?? '#22c55e';
    const count = cluster.getChildCount();
    const size = count < 10 ? 30 : count < 100 ? 38 : 46;
    // 裡面有被佔領命名的桶，聚合點也插旗：不然要放大到單一桶才看得到旗子
    const flag = cluster.getAllChildMarkers().some((m) => m.options.binNick)
        ? '<div style="position:absolute;top:-9px;right:-9px;font-size:13px;line-height:1;filter:drop-shadow(0 1px 1px rgba(0,0,0,.5))">🚩</div>' : '';
    return L.divIcon({
        html: `<div style="position:relative;width:${size}px;height:${size}px;background:${color}b3;border:2px solid #fff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#0f172a;box-shadow:0 0 8px ${color}66">${count}${flag}</div>`,
        className: '', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
    });
}

function renderBins() {
    if (!radarMap) return;
    const marker = (b) => L.marker([b.lat, b.lng], { icon: binIcon(b), binKind: b.kind, binNick: !!b.nick, zIndexOffset: b.nick ? 1000 : 0 })
        .bindPopup(() => createBinPopupEl(b), { maxWidth: 260 });
    if (binsLayer) radarMap.removeLayer(binsLayer);

    // 只看被佔領的：數量少，不群聚、全部攤開，並縮放到剛好框住它們（demo 一鍵到位）
    if (binLayers.occupied_only) {
        const named = binsCache.points.filter((b) => b.nick);
        binsLayer = L.layerGroup(named.map(marker)).addTo(radarMap);
        if (named.length) radarMap.fitBounds(L.latLngBounds(named.map((b) => [b.lat, b.lng])), { padding: [60, 60], maxZoom: 16 });
        return;
    }

    const layer = L.markerClusterGroup({
        maxClusterRadius: 40,
        showCoverageOnHover: true,
        polygonOptions: { color: '#15803d', weight: 1.5, opacity: 0.8, fillOpacity: 0.08 },
        spiderfyOnMaxZoom: true,
        zoomToBoundsOnClick: true,
        chunkedLoading: true,
        iconCreateFunction: binClusterIcon,
    });
    layer.addLayers(binsCache.points.map(marker));
    binsLayer = layer.addTo(radarMap);
}

// 全部桶位一次載入（全市約 800 筆）：平移縮放由 markercluster 處理，只有篩選改變或 force 才重抓
async function fetchBins({ force = false } = {}) {
    if (!radarMap) return;
    const el = document.getElementById('radar-map');
    if (!el) return;

    const kinds = [];
    if (binLayers.bins) kinds.push('clean_box');
    if (binLayers.bag_boxes) kinds.push('dog_bag_box');
    if (kinds.length === 0) {
        if (binsLayer) { radarMap.removeLayer(binsLayer); binsLayer = null; }
        binsCache = { key: null, points: [] };
        return;
    }
    const statuses = ['active', 'unverified'];
    if (binLayers.missing_bins) statuses.push('missing');

    const key = `${kinds.join(',')}|${statuses.join(',')}`;
    if (!force && binsCache.key === key) return;

    const params = new URLSearchParams({ mode: 'points', kinds: kinds.join(','), statuses: statuses.join(',') });
    if (binsFetchController) binsFetchController.abort();
    binsFetchController = new AbortController();
    try {
        const res = await fetch(`${el.dataset.binsUrl}?${params}`, { signal: binsFetchController.signal, headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        binsCache = { key, points: (await res.json()).points ?? [] };
        renderBins();
    } catch (_) { /* abort 或離線：保留現有圖層 */ }
}

// ── 有袋沒桶（一次全撈，粉紅圈）─────────────────────────────
async function renderGaps() {
    if (!radarMap) return;
    if (!gapsVisible) {
        if (gapsLayer) { radarMap.removeLayer(gapsLayer); gapsLayer = null; }
        return;
    }
    if (!gapsCache) {
        const el = document.getElementById('radar-map');
        try {
            const res = await fetch(el.dataset.gapsUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            gapsCache = await res.json();
        } catch (_) { return; }
    }
    const layer = L.layerGroup();
    gapsCache.forEach((g) => {
        const circle = L.circleMarker([g.lat, g.lng], { radius: 11, color: '#f472b6', weight: 2, fillColor: '#f472b6', fillOpacity: 0.35 });
        const popup = document.createElement('div');
        popup.style.cssText = 'font-size:13px;line-height:1.5;color:#1e293b';
        const b = document.createElement('b');
        b.textContent = `🧷 ${g.district ?? ''} ${g.name}`;
        const d = document.createElement('div');
        d.textContent = `${window.i18n?.gapBoxes ?? '狗便袋箱'} ${g.box_count}｜${window.i18n?.gapNearest ?? '最近清潔箱'} ${g.nearest_m ? g.nearest_m + ' m' : (window.i18n?.gapNone ?? '300m 內沒有')}`;
        popup.append(b, d);
        circle.bindPopup(popup, { maxWidth: 240 });
        layer.addLayer(circle);
    });
    if (gapsLayer) radarMap.removeLayer(gapsLayer);
    gapsLayer = layer.addTo(radarMap);
}

// ── 需求色塊：DemandGrid 方格（預設 150m），API 只給 [ix, iy, 分數] + grid 參數，前端自己畫格 ──
// 色階以前 2% 分數為滿色（避免單一極端格把其他格都壓淡）；分數 0 的格子不下發、不畫
const DEMAND_COLOR_STOPS = [[0, [253, 230, 138]], [0.4, [251, 146, 60]], [0.75, [239, 68, 68]], [1, [153, 27, 27]]];
function demandColor(t) {
    for (let i = 1; i < DEMAND_COLOR_STOPS.length; i++) {
        const [t1, c1] = DEMAND_COLOR_STOPS[i];
        if (t <= t1) {
            const [t0, c0] = DEMAND_COLOR_STOPS[i - 1];
            const f = (t - t0) / (t1 - t0);
            return `rgb(${c0.map((v, k) => Math.round(v + (c1[k] - v) * f)).join(',')})`;
        }
    }
    return 'rgb(153,27,27)';
}

// 載入提示：資料約百來 KB，慢速網路要看得到「有在跑」；邊讀邊顯示已下載量（壓縮傳輸拿不到總量，不做百分比）
function setDemandLoading(text) {
    const el = document.getElementById('radar-map');
    let box = el?.querySelector('.demand-loading');
    if (text === null) { box?.remove(); return; }
    if (!box) {
        box = document.createElement('div');
        box.className = 'demand-loading';
        box.innerHTML = '<span></span><div class="demand-loading-track"><div class="demand-loading-bar"></div></div>';
        el.append(box);
    }
    box.querySelector('span').textContent = text;
}

async function loadDemand() {
    const el = document.getElementById('radar-map');
    const label = window.i18n?.demandLoading ?? '需求熱力載入中…';
    setDemandLoading(label);
    try {
        const res = await fetch(el.dataset.demandUrl, { headers: { Accept: 'application/json' } });
        if (!res.ok || !res.body) return null;
        const reader = res.body.getReader();
        const chunks = [];
        let received = 0;
        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            chunks.push(value);
            received += value.length;
            setDemandLoading(`${label} ${Math.round(received / 1024)} KB`);
        }
        const payload = JSON.parse(new TextDecoder().decode(await new Blob(chunks).arrayBuffer()));
        const scores = new Map(payload.cells.map(([ix, iy, score]) => [`${ix}:${iy}`, score]));
        const sorted = payload.cells.map((c) => c[2]).sort((a, b) => a - b);
        return { grid: payload.grid, cells: payload.cells, scores, p98: sorted[Math.floor(sorted.length * 0.98)] || 1 };
    } catch (_) {
        return null;
    } finally {
        setDemandLoading(null);
    }
}

async function renderDemand() {
    if (!radarMap) return;
    syncDemandHint();
    if (!demandVisible) {
        if (demandLayer) { radarMap.removeLayer(demandLayer); demandLayer = null; }
        if (demandPopup) { radarMap.closePopup(demandPopup); demandPopup = null; }
        return;
    }
    demandCache ??= await loadDemand();
    if (!demandCache || !demandVisible) return;
    const { grid, cells, p98 } = demandCache;
    const renderer = L.canvas({ padding: 0.5 });
    const layer = L.layerGroup();
    cells.forEach(([ix, iy, score]) => {
        const t = Math.min(1, score / p98);
        const south = grid.lat0 + iy * grid.dlat;
        const west = grid.lng0 + ix * grid.dlng;
        layer.addLayer(L.rectangle([[south, west], [south + grid.dlat, west + grid.dlng]], {
            renderer, interactive: false, weight: 0.5, color: '#9a3412', opacity: 0.2,
            fillColor: demandColor(t), fillOpacity: 0.15 + 0.55 * t,
        }));
    });
    if (demandLayer) radarMap.removeLayer(demandLayer);
    demandLayer = layer.addTo(radarMap);
}

/** 經緯度所在格的分數（沒色塊回 0）；與後端 DemandGrid::cellAt 同一套換算 */
function demandScoreAt(latlng) {
    if (!demandVisible || !demandCache) return 0;
    const { grid, scores } = demandCache;
    const ix = Math.floor((latlng.lng - grid.lng0) / grid.dlng);
    const iy = Math.floor((latlng.lat - grid.lat0) / grid.dlat);
    return scores.get(`${ix}:${iy}`) ?? 0;
}

// ── 點色塊看明細：點擊位置所在格的分數組成，並以虛線框出該格（一塊紅要說得出為什麼紅）──
let demandPopup = null;
async function showDemandDetail(latlng) {
    const el = document.getElementById('radar-map');
    let cell = null;
    try {
        const res = await fetch(`${el.dataset.demandAtUrl}?lat=${latlng.lat}&lng=${latlng.lng}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        ({ cell } = await res.json());
    } catch (_) { return; }
    if (!demandVisible) return;
    dismissDemandHint(); // 點過一次就知道能點了

    const box = document.createElement('div');
    box.style.cssText = 'font-size:13px;line-height:1.5;color:#1e293b;min-width:210px';
    const popup = L.popup({ maxWidth: 300 }).setLatLng(latlng);
    if (!cell) {
        box.textContent = window.i18n?.demandEmpty ?? '這一格目前沒有需求訊號';
        demandPopup = popup.setContent(box).openOn(radarMap);
        return;
    }

    const title = document.createElement('b');
    title.textContent = `🔥 ${window.i18n?.demandScore ?? '需求分數'} ${cell.score.toFixed(1)}`;
    const place = document.createElement('div');
    place.style.cssText = 'font-size:11px;color:#64748b;margin-bottom:4px';
    place.textContent = cell.title;
    box.append(title, place);
    if (cell.outside_taipei && cell.outside_note) {
        const note = document.createElement('div');
        note.style.cssText = 'font-size:11px;color:#9a3412;background:#ffedd5;border-radius:6px;padding:4px 6px;margin-bottom:4px';
        note.textContent = cell.outside_note;
        box.append(note);
    }
    cell.items.forEach((item) => {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;justify-content:space-between;gap:12px;border-top:1px solid #e2e8f0;padding:2px 0';
        const left = document.createElement('div');
        left.textContent = item.key === 'bin_gap' ? item.label : `${item.label} × ${item.count}`;
        if (item.note) {
            const note = document.createElement('div');
            note.style.cssText = 'font-size:11px;color:#64748b';
            note.textContent = item.note;
            left.append(note);
        }
        const score = document.createElement('span');
        score.style.cssText = `font-weight:700;white-space:nowrap;color:${item.score < 0 ? '#e11d48' : '#059669'}`;
        score.textContent = `${item.score > 0 ? '+' : ''}${item.score.toFixed(1)}`;
        row.append(left, score);
        box.append(row);
    });

    // 每個 popup 只收自己的框線：連點兩格時舊 popup 的 remove 會在新框線畫上之後才觸發
    const b = cell.bounds;
    const outline = L.rectangle([[b.south, b.west], [b.north, b.east]],
        { color: '#ea580c', weight: 2, dashArray: '6 4', fill: false, interactive: false }).addTo(radarMap);
    popup.on('remove', () => radarMap.removeLayer(outline));
    demandPopup = popup.setContent(box).openOn(radarMap);
}

// ── 熱力可點提示：熱力預設開著，但看不出紅色區塊能點。按 ✕ 或點過一次後不再出現（記在本機）──
const DEMAND_HINT_KEY = 'petmin.demandHintDismissed';
let demandHint = null;
function demandHintDismissed() {
    try { return localStorage.getItem(DEMAND_HINT_KEY) === '1'; } catch (_) { return false; }
}
function dismissDemandHint() {
    try { localStorage.setItem(DEMAND_HINT_KEY, '1'); } catch (_) {}
    if (demandHint) { demandHint.remove(); demandHint = null; }
}
function syncDemandHint() {
    const show = demandVisible && !demandHintDismissed();
    if (show && !demandHint) {
        demandHint = L.control({ position: 'topleft' });
        demandHint.onAdd = () => {
            const box = L.DomUtil.create('div', 'leaflet-demand-hint');
            const text = document.createElement('span');
            text.textContent = window.i18n?.demandHint ?? '🔥 點紅色區塊，看為什麼需要桶';
            const close = document.createElement('button');
            close.type = 'button';
            close.textContent = '✕';
            close.setAttribute('aria-label', window.i18n?.demandHintClose ?? '不再提示');
            close.addEventListener('click', dismissDemandHint);
            box.append(text, close);
            L.DomEvent.disableClickPropagation(box);
            return box;
        };
        demandHint.addTo(radarMap);
    } else if (!show && demandHint) {
        demandHint.remove();
        demandHint = null;
    }
}

// 電腦版：滑到有色塊的格子游標變手指（直接算滑鼠在哪一格，不用 hit-test 8,000 個方格）
function initDemandCursor() {
    const container = radarMap.getContainer();
    radarMap.on('mousemove', (e) => container.classList.toggle('demand-hot', demandScoreAt(e.latlng) > 0));
    radarMap.on('mouseout', () => container.classList.remove('demand-hot'));
}

// ── 分類篩選 chips（radar 頁 header 渲染，data-map-chip 綁定）──
function initChips() {
    const setStatus = (key, on) => { on ? activeStatuses.add(key) : activeStatuses.delete(key); };
    const layerToggles = {
        bins: (on) => { binLayers.bins = on; fetchBins(); },
        bag_boxes: (on) => { binLayers.bag_boxes = on; fetchBins(); },
        missing_bins: (on) => { binLayers.missing_bins = on; fetchBins(); },
        occupied_only: (on) => { binLayers.occupied_only = on; renderBins(); },
        gaps: (on) => { gapsVisible = on; renderGaps(); },
        demand: (on) => { demandVisible = on; renderDemand(); },
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
            const t = window.i18n ?? {};
            btn.textContent = currentTheme === 'dark' ? (t.mapLightMode ?? '☀️ 亮色模式') : (t.mapDarkMode ?? '🌙 暗色模式');
            btn.title = currentTheme === 'dark' ? (t.mapLightModeTitle ?? '切換至亮色地圖') : (t.mapDarkModeTitle ?? '切換至暗色地圖');
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

    const onMove = () => { scheduleFetch(); fetchBins(); };
    radarMap.on('moveend', onMove);
    radarMap.on('zoomend', onMove);
    radarMap.on('click', (e) => { if (demandScoreAt(e.latlng) > 0) showDemandDetail(e.latlng); });
    fetchReports();
    fetchBins();

    // /candidates 的「看地圖」深連結：#map=lat,lng,zoom
    const hash = /^#map=(-?[\d.]+),(-?[\d.]+),(\d+)$/.exec(window.location.hash);
    if (hash) {
        radarMap.setView([parseFloat(hash[1]), parseFloat(hash[2])], parseInt(hash[3], 10));
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

    // 縮放鈕跟暗色模式、搜尋排在左上同一列（右上留給「圖層」鈕）
    L.control.zoom({ position: 'topleft' }).addTo(radarMap);

    // 📍 回到我的位置：緊接在縮放鈕下面
    initMyLocation(el, { recenter: !hash });

    // 熱力預設開啟；放在搜尋鈕之後，提示才會排在左上控制項最下面
    renderDemand();
    initDemandCursor();

    initChips();
}

// ── 我的位置：中心點 + 雷達圈（附近 N 公里內有什麼一眼看得到）──────────
// 第一次定位把地圖框到雷達圈；之後跟著使用者移動（遛狗會邊走邊看），但不再搶畫面。
// 左上「📍」鈕回到自己的位置。雷達圈不吃點擊，點圈內的需求格子照常看分數組成。
function initMyLocation(mapEl, { recenter }) {
    if (!navigator.geolocation) return;
    const radiusM = parseFloat(mapEl.dataset.radarRadiusM) || 1000;
    const label = radiusM >= 1000 ? `${+(radiusM / 1000).toFixed(1)} km` : `${radiusM} m`;
    let here = null;
    let dot = null;
    let ring = null;
    let ringLabel = null;

    const show = (latlng) => {
        here = latlng;
        if (!dot) {
            ring = L.circle(latlng, { radius: radiusM, color: '#0ea5e9', weight: 2, dashArray: '6 6', fillColor: '#0ea5e9', fillOpacity: 0.06, interactive: false }).addTo(radarMap);
            ringLabel = L.marker(latlng, {
                icon: L.divIcon({ className: '', html: `<div class="my-radar-label">${label}</div>`, iconSize: [0, 0] }),
                interactive: false, keyboard: false,
            }).addTo(radarMap);
            dot = L.marker(latlng, {
                icon: L.divIcon({ className: '', html: '<div class="my-location-dot"><span></span></div>', iconSize: [18, 18], iconAnchor: [9, 9] }),
                interactive: false, keyboard: false, zIndexOffset: 2000,
            }).addTo(radarMap);
        }
        dot.setLatLng(latlng);
        ring.setLatLng(latlng);
        // 標籤放在圈的正上緣
        ringLabel.setLatLng(L.latLng(latlng.lat + radiusM / 111320, latlng.lng));
    };
    const frame = () => here && radarMap.fitBounds(ring.getBounds(), { padding: [24, 24] });

    let first = true;
    navigator.geolocation.watchPosition(
        (pos) => {
            show(L.latLng(pos.coords.latitude, pos.coords.longitude));
            if (first && recenter) frame();
            first = false;
        },
        () => {},
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 15000 },
    );

    const LocateControl = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const bar = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
            const btn = L.DomUtil.create('a', 'leaflet-control-my-location', bar);
            btn.href = '#';
            btn.role = 'button';
            btn.title = window.i18n?.myLocation ?? '📍 我的位置';
            btn.textContent = '📍';
            L.DomEvent.on(btn, 'click', (ev) => {
                L.DomEvent.preventDefault(ev);
                L.DomEvent.stopPropagation(ev);
                if (here) {
                    frame();
                    return;
                }
                navigator.geolocation.getCurrentPosition(
                    (pos) => { show(L.latLng(pos.coords.latitude, pos.coords.longitude)); frame(); },
                    () => {},
                    { enableHighAccuracy: true, timeout: 8000 },
                );
            });
            return bar;
        },
    });
    new LocateControl().addTo(radarMap);
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
    // 從桶 popup「回報這個桶」進來：表單已帶位置，直接定位、不再抓 GPS
    const presetLat = parseFloat(el.dataset.presetLat);
    const presetLng = parseFloat(el.dataset.presetLng);
    const hasPreset = Number.isFinite(presetLat) && Number.isFinite(presetLng);
    const nearbyUrl = el.dataset.nearbyUrl;
    let snapController = null;

    // 位置定好後查最近的桶（snap 距離內），有就吸附；need_bin 類別由 Livewire 端忽略 trash_bin_id
    const snapToBin = (lat, lng) => {
        if (!nearbyUrl) return;
        if (snapController) snapController.abort();
        snapController = new AbortController();
        fetch(`${nearbyUrl}?lat=${lat}&lng=${lng}`, { signal: snapController.signal, headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                if (!data) return;
                if (data.bin) wire.setSnappedBin(data.bin.id, data.bin.name ?? '', data.bin.kind, data.bin.distance_m);
                else wire.clearAutoSnappedBin();
            })
            .catch(() => {});
    };

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
        snapToBin(lat, lng);
    });

    const setPos = (lat, lng) => {
        skipNextMoveEnd = true;
        map.setView([lat, lng], 18);
        wire.setLocation(lat, lng);
        reverseGeocode(lat, lng);
        snapToBin(lat, lng);
    };

    // 接收 EXIF 解析成功後的位置事件
    // 從特定桶進來（打卡頁、popup）位置就是那個桶，照片 GPS 不移動圖釘、不重新吸附
    const onExifLocation = (e) => { if (!hasPreset) setPos(e.detail.lat, e.detail.lng); };
    window.addEventListener('exif-location', onExifLocation);
    // 元件移除時清理事件
    el.__exifCleanup = () => window.removeEventListener('exif-location', onExifLocation);
    // 表單關閉時 Livewire 以 @if 移除整個 DOM，下次開啟是新元素、新地圖；舊地圖要由 Alpine destroy() 移除，
    // 否則每開一次表單就多留一個 WebGL context，累積超過瀏覽器上限時會擠掉主地圖的底圖
    el.__pinMap = map;

    if (hasPreset) {
        setPos(presetLat, presetLng);
    } else if (navigator.geolocation) {
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
    // 跑馬燈是全站共用 head nav 的一部分，獨立於地圖之外呼叫，次要頁面也跑得到。
    initTicker();

    window.addEventListener('petmin:report-clicked', () => {
        window.Livewire.dispatch('open-report-form');
    });

    // 回報送出或後端通知資料更新 → 重新向 bbox API 取點位；桶狀態也可能被核准回報改掉，強制重抓
    window.addEventListener('petmin:reports-updated', () => {
        scheduleFetch();
        fetchBins({ force: true });
    });
});
