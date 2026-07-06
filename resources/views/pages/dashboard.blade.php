@extends('layouts.app')

@section('title', 'CivicShield EOC 戰情室 | 台北市災情情勢圖')

@section('content')
{{-- EOC 大屏（公開唯讀，spec §7.3）：統計/跑馬燈 wire:poll.10s；
     雨量趨勢圖與地圖圖層各自依源週期刷新，整頁每 10 分鐘重載兜底 --}}
<meta http-equiv="refresh" content="600">

<livewire:eoc-dashboard />

<script>
    document.addEventListener('livewire:initialized', () => {
        const canvas = document.getElementById('rain-trend-chart');
        if (! canvas || ! window.Chart) return;

        const trend = JSON.parse(canvas.dataset.trend || '[]');
        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: trend.map((t) => t.h),
                datasets: [{
                    data: trend.map((t) => t.v),
                    backgroundColor: trend.map((t) => t.v >= 40 ? '#f87171' : t.v >= 15 ? '#fb923c' : '#38bdf8'),
                    borderRadius: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => `${c.parsed.y} mm` } } },
                scales: {
                    x: { ticks: { color: '#475569', font: { size: 8 }, maxTicksLimit: 12 }, grid: { display: false } },
                    y: { ticks: { color: '#475569', font: { size: 8 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
                },
            },
        });
    });
</script>
@endsection
