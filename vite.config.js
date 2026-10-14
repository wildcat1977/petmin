import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        // Tailwind v4 的色票是 oklch()，Chrome 109（Win7/8 最後一版）、iOS 15 Safari 不認得 →
        // 整個底色失效變透明，白字按鈕變白底白字（洋流好店使用者回報找不到送出鈕）。
        // 把這些瀏覽器列入目標，Lightning CSS 會補 hex 預設值、新瀏覽器再以 @supports 用原色。
        cssTarget: ['chrome109', 'edge109', 'firefox115', 'safari15'],
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
