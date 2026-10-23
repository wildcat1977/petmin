<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('巡桶貼紙列印 · Petmin') }}</title>
    {{-- 只有這一頁用 QR 產生器，從 CDN 載入、不進 npm 依賴 --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e7ece9; color: #10241d; font-family: "Noto Sans TC", "PingFang TC", system-ui, sans-serif; }
        .bar { max-width: 210mm; margin: 0 auto; padding: 16px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .bar p { margin: 0; font-size: 14px; color: #3d5a50; }
        .bar button { border: 0; border-radius: 10px; padding: 10px 18px; background: #002923; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
        /* A4 直式、2 欄 × 4 列，每張約 90 × 64mm */
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 24px; padding: 10mm; background: #fff; display: grid; grid-template-columns: repeat(2, 1fr); grid-auto-rows: 66mm; gap: 6mm; }
        .sticker { border: 1.5px dashed #9fb5ac; border-radius: 4mm; padding: 4mm; display: grid; grid-template-columns: 34mm 1fr; gap: 4mm; align-items: center; overflow: hidden; }
        .qr { width: 34mm; height: 34mm; }
        .qr img, .qr canvas { width: 34mm !important; height: 34mm !important; }
        .brand { font-weight: 900; font-size: 15pt; color: #002923; }
        .brand span { color: #e0a100; }
        .cta { font-size: 10pt; font-weight: 700; margin-top: 1.5mm; line-height: 1.35; }
        .name { font-size: 9pt; color: #3d5a50; margin-top: 2mm; line-height: 1.3; }
        .code { font-family: ui-monospace, Menlo, monospace; font-size: 6.5pt; color: #7a8f87; margin-top: 1mm; word-break: break-all; }
        .empty { max-width: 210mm; margin: 40px auto; text-align: center; }
        @media print {
            @page { size: A4; margin: 0; }
            body { background: #fff; }
            .bar { display: none; }
            .sheet { margin: 0; page-break-after: always; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <p>{{ __('共 :n 張。用瀏覽器列印（Cmd＋P），紙張 A4、邊界「無」、縮放 100%。', ['n' => $stickers->count()]) }}</p>
        <button type="button" onclick="window.print()">{{ __('列印') }}</button>
    </div>

    @forelse ($stickers->chunk(8) as $page)
        <div class="sheet">
            @foreach ($page as $s)
                <div class="sticker">
                    <div class="qr" data-qr="{{ $s['url'] }}"></div>
                    <div>
                        <div class="brand">Petmin<span> 🐾</span></div>
                        <div class="cta">{{ __('碰一下或掃一下，回報這個桶') }}</div>
                        <div class="name">🗑️ {{ $s['name'] }}{{ $s['district'] ? ' · '.$s['district'] : '' }}</div>
                        <div class="code">{{ $s['code'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @empty
        <p class="empty">{{ __('找不到符合的桶。') }}</p>
    @endforelse

    <script>
        document.querySelectorAll('[data-qr]').forEach((el) => {
            if (typeof QRCode === 'undefined') { el.textContent = el.dataset.qr; return; }
            new QRCode(el, { text: el.dataset.qr, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
        });
    </script>
</body>
</html>
