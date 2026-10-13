# Open data 快照（匯入指令讀本機檔，不依賴現場網路）

| 檔案 | 來源 | 筆數 | 指令 |
|---|---|---|---|
| clean_boxes.csv | data.gov.tw 臺北市行人專用清潔箱（環保局；Big5 已轉 UTF-8） | 681 | `php artisan bins:import-clean-boxes` |
| dog_bag_boxes.csv | data.gov.tw 狗便清潔袋箱位置總表（每筆為地點，note 為「N處」） | 130 | `php artisan bins:import-dog-bag-boxes` |
| bus_stops.csv | data.taipei 臺北市公車站牌位置圖（SHP → WGS84 CSV） | 3,407 | `php artisan proxies:import-bus-stops` |
| mrt_stations.csv | 待補：站名,lng,lat[,weight]（捷運站點座標；weight 可由月 OD 檔算出） | — | `php artisan proxies:import-mrt` |
| dog_parks.json | 手刻：公園狗活動區／河濱遛狗熱點 | — | `php artisan proxies:import-dog-parks` |

注意：清潔箱清單是「目前現存」的（681 組，接近 2026 年新聞的 806 組），
不是裁撤前的 ~1,900 組；「消失的桶」只能靠市民回報「這裡以前有桶」累積。
