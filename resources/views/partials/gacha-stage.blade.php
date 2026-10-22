{{--
  扭蛋劇情播放器：收到 gacha-play（由 livewire:gacha-player 發出）就全螢幕播一段劇情，結尾翻出抽到的卡。
  劇情對應 config petmin.gacha.cards 的 story；素材在 public/gacha/。目前是 demo 版動畫，細節之後再調。
--}}
<div data-gacha-root class="gacha-root" hidden>
    <div class="gacha-stage" data-gacha-stage><div class="gacha-caption" data-gacha-caption></div></div>
    <button type="button" class="gacha-skip" data-gacha-skip>{{ __('略過') }}</button>
</div>

<style>
    .gacha-root { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; background: rgba(4, 14, 10, .82); backdrop-filter: blur(4px); padding: max(16px, env(safe-area-inset-top, 0px)) 16px max(16px, env(safe-area-inset-bottom, 0px)); }
    .gacha-root[hidden] { display: none; }
    .gacha-stage { position: relative; width: min(100%, 420px, 62dvh); aspect-ratio: 2 / 3; border-radius: 22px; overflow: hidden; background: #0e1c16 center / cover no-repeat; box-shadow: 0 10px 30px rgba(0,0,0,.45); }
    .gacha-skip { position: absolute; top: max(16px, env(safe-area-inset-top, 0px)); right: 16px; border: 0; border-radius: 999px; padding: 6px 14px; background: rgba(255,255,255,.15); color: #fff; font-size: 14px; }
    .gacha-sp { position: absolute; display: block; height: auto; pointer-events: none; transition: left .7s cubic-bezier(.5,0,.3,1), top .7s cubic-bezier(.5,0,.3,1), transform .5s cubic-bezier(.3,1.5,.5,1), opacity .4s; filter: drop-shadow(0 6px 6px rgba(0,0,0,.28)); }
    .gacha-pop { animation: gacha-pop .55s cubic-bezier(.3,1.6,.5,1) both; }
    @keyframes gacha-pop { from { transform: scale(.2); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .gacha-float { animation: gacha-float 1.8s ease-in-out infinite; }
    @keyframes gacha-float { 0%,100% { translate: 0 0; } 50% { translate: 0 -7px; } }
    .gacha-shake { animation: gacha-shake .16s infinite; }
    @keyframes gacha-shake { 0%,100% { translate: 0 0; } 25% { translate: -3px 0; } 75% { translate: 3px 0; } }
    .gacha-hop { animation: gacha-hop .45s ease-in-out infinite; }
    @keyframes gacha-hop { 0%,100% { translate: 0 0; } 50% { translate: 0 -12px; } }
    .gacha-rise { animation: gacha-rise 1.1s cubic-bezier(.2,1.2,.4,1) both; }
    @keyframes gacha-rise { from { transform: translateY(45%) scale(.25); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
    .gacha-bubble { position: absolute; z-index: 40; background: #fff; color: #2b2320; border: 3px solid #2b2320; border-radius: 18px; padding: 8px 12px; font-size: 15px; font-weight: 800; line-height: 1.4; max-width: 82%; box-shadow: 0 4px 0 rgba(0,0,0,.25); animation: gacha-pop .4s cubic-bezier(.3,1.6,.5,1) both; }
    .gacha-caption { position: absolute; left: 10px; right: 10px; bottom: 10px; z-index: 50; background: rgba(20,16,14,.78); color: #fff; font-size: 14px; font-weight: 700; padding: 8px 12px; border-radius: 12px; text-align: center; }
    .gacha-caption:empty { display: none; }
    .gacha-smoke { position: absolute; z-index: 5; border-radius: 50%; background: radial-gradient(#fff, rgba(255,255,255,.6) 60%, rgba(255,255,255,0) 70%); animation: gacha-puff 1.5s ease-out forwards; }
    @keyframes gacha-puff { from { transform: scale(.2); opacity: 1; } to { transform: scale(2.4); opacity: 0; } }
    .gacha-spark { position: absolute; z-index: 45; width: 22px; height: 22px; background: radial-gradient(#fff 15%, #ffe066 35%, rgba(255,224,102,0) 70%); clip-path: polygon(50% 0,62% 38%,100% 50%,62% 62%,50% 100%,38% 62%,0 50%,38% 38%); animation: gacha-twinkle .9s ease-in-out infinite; }
    @keyframes gacha-twinkle { 0%,100% { transform: scale(.5); opacity: .3; } 50% { transform: scale(1.2) rotate(30deg); opacity: 1; } }
    .gacha-label { position: absolute; z-index: 30; transform: translateX(-50%); background: #2b2320; color: #fff; font-size: 12px; font-weight: 800; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
    .gacha-label.bad { background: #c0392b; }
    .gacha-flash { position: absolute; inset: 0; z-index: 60; background: #fff; pointer-events: none; animation: gacha-flash .45s ease-out forwards; }
    @keyframes gacha-flash { from { opacity: .85; } to { opacity: 0; } }
    .gacha-reward { position: absolute; inset: 0; z-index: 70; display: flex; align-items: center; justify-content: center; background: rgba(10,18,14,.55); }
    .gacha-reward .box { width: 82%; background: linear-gradient(#fffaf0, #fff1cf); color: #2b2320; border: 4px solid #2b2320; border-radius: 22px; padding: 16px 14px 14px; text-align: center; box-shadow: 0 0 0 6px #ffd65a, 0 12px 30px rgba(0,0,0,.4); animation: gacha-pop .5s cubic-bezier(.3,1.6,.5,1) both; }
    .gacha-reward img { width: 46%; height: auto; display: block; margin: 0 auto 6px; }
    .gacha-reward .t { font-size: 19px; font-weight: 900; }
    .gacha-reward .s { font-size: 13px; color: #7a6d64; margin-top: 4px; }
    .gacha-reward .n { font-size: 12px; color: #2f8f5b; font-weight: 800; margin-top: 6px; }
    .gacha-reward .acts { display: flex; gap: 8px; margin-top: 12px; }
    .gacha-reward .acts a, .gacha-reward .acts button { flex: 1; border-radius: 10px; padding: 9px 4px; font-size: 14px; font-weight: 800; text-align: center; }
    .gacha-reward .acts a { background: #2f8f5b; color: #fff; }
    .gacha-reward .acts button { background: #fff; border: 2px solid #2b2320; color: #2b2320; }
    .gacha-reward .close-link { margin-top: 10px; background: none; border: 0; color: #7a6d64; font-size: 13px; text-decoration: underline; }
    @media (prefers-reduced-motion: reduce) { .gacha-root * { animation-duration: .01s !important; transition-duration: .01s !important; } }
</style>

<script>
(() => {
    const ART = '/gacha/';
    const root = document.querySelector('[data-gacha-root]');
    if (!root) return;
    const stage = root.querySelector('[data-gacha-stage]');
    let runId = 0;
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const alive = (id) => id === runId;

    function reset(bg) {
        stage.innerHTML = '<div class="gacha-caption" data-gacha-caption></div>';
        stage.style.backgroundImage = `url(${ART}${bg}.webp)`;
    }
    const caption = (t) => { stage.querySelector('[data-gacha-caption]').textContent = t; };
    function sprite(name, { x, y, w, z = 10, cls = '' }) {
        const img = document.createElement('img');
        img.className = 'gacha-sp ' + cls;
        img.src = ART + name + '.webp';
        img.alt = '';
        Object.assign(img.style, { left: x + '%', top: y + '%', width: w + '%', zIndex: z });
        // 登場動畫播完就拿掉，否則 fill-mode 佔住 transform，翻牌、位移會失效
        img.addEventListener('animationend', (e) => {
            if (e.animationName === 'gacha-pop' || e.animationName === 'gacha-rise') img.classList.remove('gacha-pop', 'gacha-rise');
        });
        stage.appendChild(img);
        return img;
    }
    function swap(img, name, cls) {
        img.src = ART + name + '.webp';
        img.classList.remove('gacha-shake', 'gacha-hop', 'gacha-float');
        if (cls) img.classList.add(cls);
    }
    const moveTo = (img, x, y) => { img.style.left = x + '%'; img.style.top = y + '%'; };
    function bubble(text, x, y) {
        const b = document.createElement('div');
        b.className = 'gacha-bubble';
        b.textContent = text;
        Object.assign(b.style, { left: x + '%', top: y + '%' });
        stage.appendChild(b);
        return b;
    }
    function sparks(n, x, y, w, h) {
        for (let i = 0; i < n; i++) {
            const s = document.createElement('div');
            s.className = 'gacha-spark';
            Object.assign(s.style, { left: x + Math.random() * w + '%', top: y + Math.random() * h + '%', animationDelay: (i * 0.12) + 's' });
            stage.appendChild(s);
        }
    }
    function smoke(x, y) {
        for (let i = 0; i < 7; i++) {
            const s = document.createElement('div');
            s.className = 'gacha-smoke';
            const size = 50 + i * 10;
            Object.assign(s.style, { width: size + 'px', height: size + 'px', left: `calc(${x + (i % 3 - 1) * 6}% - ${size / 2}px)`, top: `calc(${y - i * 2.5}% - ${size / 2}px)`, animationDelay: (i * 0.07) + 's' });
            stage.appendChild(s);
        }
    }
    function flash() { const f = document.createElement('div'); f.className = 'gacha-flash'; stage.appendChild(f); }

    function reward(detail) {
        const r = document.createElement('div');
        r.className = 'gacha-reward';
        r.innerHTML = '<div class="box"><img alt=""><div class="t"></div><div class="s"></div><div class="n"></div><div class="acts"><a href="/profile#dog-cards"></a><button type="button" data-share></button></div><button type="button" class="close-link" data-close></button></div>';
        r.querySelector('img').src = ART + detail.card.image + '.webp';
        r.querySelector('.t').textContent = detail.card.title;
        r.querySelector('.s').textContent = detail.card.line;
        r.querySelector('.n').textContent = `狗狗圖鑑 ${detail.collected} / ${detail.total}`;
        r.querySelector('a').textContent = '看我的狗狗圖鑑';
        const shareBtn = r.querySelector('[data-share]');
        shareBtn.textContent = '分享';
        shareBtn.addEventListener('click', () => shareCard(detail.card, shareBtn));
        const closeBtn = r.querySelector('[data-close]');
        closeBtn.textContent = '關閉';
        closeBtn.addEventListener('click', close);
        stage.appendChild(r);
    }

    // 分享：手機支援就叫出原生分享（附卡片預覽圖＋連結），不支援就複製連結。
    // Web Share 只在 https 才能用，本機 http 測試會走複製連結
    async function shareCard(card, btn) {
        const url = `${location.origin}/card/${card.key}`;
        const text = `我的狗狗抽到了「${card.title}」！散步順手檢查垃圾桶，一起出任務吧`;
        try {
            if (navigator.share) {
                const data = { title: card.title, text, url };
                if (card.share_image && navigator.canShare) {
                    const blob = await fetch(`${ART}${card.share_image}`).then((r) => r.blob());
                    const file = new File([blob], `${card.key}.jpg`, { type: 'image/jpeg' });
                    if (navigator.canShare({ files: [file] })) data.files = [file];
                }
                await navigator.share(data);
                return;
            }
        } catch (e) {
            if (e && e.name === 'AbortError') return; // 使用者自己取消分享
        }
        const ok = await copyText(url);
        btn.textContent = ok ? '已複製連結' : url;
    }
    async function copyText(text) {
        try { await navigator.clipboard.writeText(text); return true; } catch (e) {}
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.top = '-9999px';
        document.body.appendChild(ta); ta.select();
        try { return document.execCommand('copy'); } catch (e) { return false; } finally { ta.remove(); }
    }

    // ── 劇情：金銀垃圾桶 ─────────────────────
    async function goldSilver(id) {
        reset('bg-street');
        const owner = sprite('owner-left', { x: 3, y: 48, w: 26, z: 20, cls: 'gacha-pop' });
        const dog = sprite('dog-neutral', { x: 60, y: 66, w: 36, z: 20, cls: 'gacha-pop' });
        caption('今天也是愉快的散步……');
        await wait(1500); if (!alive(id)) return false;
        smoke(50, 76); flash();
        const god = sprite('god-rise', { x: 22, y: 6, w: 56, z: 15, cls: 'gacha-rise' });
        swap(dog, 'dog-nervous', 'gacha-shake'); dog.style.width = '30%';
        caption('路邊的水溝蓋冒出了一位神！');
        await wait(1700); if (!alive(id)) return false;
        god.classList.add('gacha-float');
        const q = bubble('你要找的是金垃圾桶、銀垃圾桶，還是普通垃圾桶呢？', 8, 2);
        caption('');
        const bins = [['bin-gold', 30], ['bin-silver', 47], ['bin-plain', 64]].map(([n, x]) => sprite(n, { x, y: 38, w: 16, z: 25, cls: 'gacha-pop' }));
        await wait(1200); if (!alive(id)) return false;
        bins.forEach((b) => b.classList.add('gacha-float'));
        await wait(1300); if (!alive(id)) return false;
        q.remove();
        caption('主人提著便便袋，左看看、右看看……狗狗好緊張');
        for (let i = 0; i < 5; i++) { swap(owner, i % 2 ? 'owner-left' : 'owner-right'); await wait(560); if (!alive(id)) return false; }
        swap(owner, 'owner-shrug'); caption('');
        const a = bubble('都可以，我只想丟屎。', 4, 40);
        await wait(2000); if (!alive(id)) return false;
        a.remove();
        swap(god, 'god-bless', 'gacha-float');
        const g = bubble('太好了，你真是個誠實的人！三種垃圾桶都給你吧！', 8, 2);
        await wait(900); if (!alive(id)) return false;
        bins.forEach((b, i) => { b.classList.remove('gacha-float'); b.style.width = '14%'; moveTo(b, 28 + i * 11, 74); });
        swap(dog, 'dog-happy', 'gacha-hop'); dog.style.width = '26%'; moveTo(dog, 70, 52);
        swap(owner, 'owner-joy'); sparks(8, 26, 70, 40, 14);
        await wait(2200); if (!alive(id)) return false;
        g.remove();
        return true;
    }

    // ── 劇情：抽鬼牌（第一人稱） ─────────────
    async function oldMaid(id) {
        reset('bg-table');
        const dog = sprite('dog-nervous', { x: 33, y: 2, w: 34, z: 10, cls: 'gacha-pop' });
        const dogX = [27, 41, 55];
        const dogCards = dogX.map((x, i) => { const c = sprite('card-back', { x, y: 26, w: 18, z: 20, cls: 'gacha-pop' }); c.style.rotate = ((i - 1) * 9) + 'deg'; return c; });
        const mine = [['card-20', '20% 滿'], ['card-40', '40% 滿'], ['card-120', '120% 滿']].map(([n, t], i) => {
            const x = 16 + i * 23;
            const c = sprite(n, { x, y: 70, w: 22, z: 30, cls: 'gacha-pop' });
            c.style.rotate = ((i - 1) * 7) + 'deg';
            const l = document.createElement('div');
            l.className = 'gacha-label' + (i === 2 ? ' bad' : '');
            l.textContent = t;
            Object.assign(l.style, { left: (x + 11) + '%', top: '66%' });
            stage.appendChild(l);
            return c;
        });
        const hand = sprite('hand-reach', { x: -6, y: 44, w: 34, z: 35 });
        caption('換我抽狗狗的牌了');
        await wait(1700); if (!alive(id)) return false;
        const moods = ['dog-sad', 'dog-happy', 'dog-sad'];
        caption('手晃到哪張，狗狗的表情就跟著變……');
        for (const idx of [0, 1, 2, 1, 0, 1, 2, 1]) {
            moveTo(hand, dogX[idx] - 12, 38);
            const m = moods[idx];
            swap(dog, m, m === 'dog-happy' ? 'gacha-hop' : '');
            if (m === 'dog-sad') { dog.style.width = '40%'; moveTo(dog, 30, 12); } else { dog.style.width = '28%'; moveTo(dog, 36, 0); }
            await wait(720); if (!alive(id)) return false;
        }
        caption('就抽中間這張！');
        const picked = dogCards[1];
        picked.style.rotate = '0deg'; picked.style.zIndex = 45; picked.style.width = '24%';
        moveTo(picked, 38, 36); moveTo(hand, -6, 44);
        await wait(900); if (!alive(id)) return false;
        picked.style.transform = 'scaleX(0)';
        await wait(260); if (!alive(id)) return false;
        picked.src = ART + 'card-clean.webp'; picked.style.transform = 'scaleX(1)';
        flash(); sparks(9, 34, 34, 32, 26);
        caption('翻開是……超級乾淨的垃圾桶！');
        await wait(1500); if (!alive(id)) return false;
        swap(dog, 'dog-rich', 'gacha-float'); dog.style.width = '32%'; moveTo(dog, 34, 0);
        caption('');
        bubble('汪！我是垃圾桶富翁！', 52, 3);
        mine.forEach((c) => { c.style.transform = 'translateY(6%)'; });
        await wait(1800); if (!alive(id)) return false;
        return true;
    }

    const STORIES = { 'gold-silver': goldSilver, 'old-maid': oldMaid };

    function close() { runId++; root.hidden = true; stage.innerHTML = ''; }
    root.querySelector('[data-gacha-skip]').addEventListener('click', () => {
        // 略過＝直接看抽到的卡
        if (!root._detail) return close();
        runId++;
        reset(root._detail.story === 'old-maid' ? 'bg-table' : 'bg-street');
        reward(root._detail);
    });

    window.addEventListener('gacha-play', async (e) => {
        const detail = Array.isArray(e.detail) ? e.detail[0] : e.detail;
        const play = STORIES[detail?.story];
        if (!play) return;
        const id = ++runId;
        root._detail = detail;
        root.hidden = false;
        // 讓回報成功的畫面先出現一下，再進劇情
        await wait(500); if (!alive(id)) return;
        if (await play(id)) reward(detail);
    });
})();
</script>
