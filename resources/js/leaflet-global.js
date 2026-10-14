import L from 'leaflet';

// 舊式 Leaflet 外掛（leaflet.markercluster）在模組載入時就讀全域 L；Leaflet 的 ESM 版不會自己掛 window.L，
// 所以要在 import 外掛之前先掛好——ES module 依 import 順序執行，app.js 先 import 這支再 import 外掛。
window.L = L;

export default L;
