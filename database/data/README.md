# Open data 快照（匯入指令讀本機檔，不依賴現場網路）

| 檔案 | 來源 | 筆數 | 指令 |
|---|---|---|---|
| clean_boxes.csv | data.gov.tw 臺北市行人專用清潔箱（環保局；Big5 已轉 UTF-8） | 681 | `php artisan bins:import-clean-boxes` |
| dog_bag_boxes.csv | data.gov.tw 狗便清潔袋箱位置總表（每筆為地點，note 為「N處」） | 130 | `php artisan bins:import-dog-bag-boxes` |
| bus_stops.csv | data.taipei 臺北市公車站牌位置圖（SHP → WGS84 CSV）；DBF 站名欄只有 22 bytes，535 個名稱在原檔就被截成「…(�」，已用臺北市公車動態資訊 GetStop.gz 修補（同 stopLocationId → 50m 內同前綴 → 都沒有則去殘字），找回 452 個全名 | 3,407 | `php artisan proxies:import-bus-stops` |
| mrt_exits.csv | data.gov.tw 臺北捷運車站出入口座標（北捷公司，1150804 版；Big5 已轉 UTF-8；含環狀線） | 390 出口 | `php artisan proxies:import-mrt-exits`（站權重取自 mrt_stations.csv，平分給各出口） |
| mrt_stations.csv | 由 mrt_exits.csv 依站名合併（出口座標取平均）＋臺北捷運每日分時各站 OD 流量 2026-08 出站量換算 weight | 119 站 | `php artisan proxies:import-mrt` |
| dog_parks.json | 手刻：公園狗活動區／河濱遛狗熱點 | — | `php artisan proxies:import-dog-parks` |

注意：清潔箱清單是「目前現存」的（681 組，接近 2026 年新聞的 806 組），
不是裁撤前的 ~1,900 組；「消失的桶」只能靠市民回報「這裡以前有桶」累積。

捷運 weight = √(該站月出站量 ÷ 最大站出站量)（台北車站 = 1；平方根避免台北車站把其他站壓到接近 0）。
OD 站名先去掉路線代碼前綴（`BL板橋` → `板橋`）再比對；環狀線 10 站 OD 無資料，給已比對站的中位數（0.309）。
OD 月檔（約 300MB）不入版控；換月時重下 `臺北捷運每日分時各站OD流量統計資料_YYYYMM.csv`，依上述規則重算 weight 欄。
