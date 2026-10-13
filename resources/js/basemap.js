import L from 'leaflet';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@maplibre/maplibre-gl-leaflet';

// 自架 Protomaps 向量底圖（docs/basemap.md）。MapLibre 以 Leaflet 圖層的形式嵌入，
// 標記、聚合、雷達回波、淹水潛勢等 Leaflet 程式都不用改。
// maplibre-gl 釘在 5.x：6.x 只出 ESM、沒有 UMD 檔，後台（CDN <script> 載入）用不了，
// worker 也要另外處理。前後台同一版本。
// 設定由 layouts/app.blade.php 注入 window.__PETMIN_BASEMAP_CONFIG__。

// 中文標籤用系統字體：Protomaps 字型只含拉丁字，CJK 字型檔太大不自架
const CJK_FONTS = "'Noto Sans TC', 'PingFang TC', 'Microsoft JhengHei', sans-serif";

const config = () => window.__PETMIN_BASEMAP_CONFIG__ ?? {};

// 探測結果快取、探測用的 context 立刻釋放：WebGL context 有數量上限，每開一張地圖就多留一個探測 context 會擠掉主地圖
let webglSupported = null;
function webglAvailable() {
    if (webglSupported === null) {
        try {
            const gl = document.createElement('canvas').getContext('webgl2') || document.createElement('canvas').getContext('webgl');
            webglSupported = Boolean(gl);
            gl?.getExtension('WEBGL_lose_context')?.loseContext();
        } catch {
            webglSupported = false;
        }
    }
    return webglSupported;
}

/** 向量底圖本身不帶 maxZoom，地圖要自己設（門牌標籤在 Leaflet z19 才出現） */
export const basemapMaxZoom = () => config().maxZoom || 19;

/**
 * 回傳底圖圖層；theme 為 'light' 或 'dark'。瀏覽器不支援 WebGL 時回傳 null（呼叫端顯示提示、點位照常）。
 * 不退回 OSM 點陣圖磚：OSM 會擋無 Referer 的請求（後台地圖曾因此 403），退回也是空白。
 */
export function createBasemapLayer(theme) {
    const style = config().styles?.[theme];
    if (!style || !webglAvailable()) return null;

    return L.maplibreGL({ style, attribution: config().attribution, localIdeographFontFamily: CJK_FONTS });
}

/** 亮／暗切換：換 style，不再用 CSS 反色（反色會把疊在同一個 tilePane 的淹水潛勢也一起反掉） */
export function setBasemapTheme(layer, theme) {
    const style = config().styles?.[theme];
    if (layer && style) layer.getMaplibreMap().setStyle(style);
}

// 給 Blade 內的 Alpine 元件（通報列表彈窗、統計頁、透明度頁）共用同一套底圖
window.__PETMIN_BASEMAP__ = { create: createBasemapLayer, maxZoom: basemapMaxZoom };
