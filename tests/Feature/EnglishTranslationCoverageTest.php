<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 非中文瀏覽器會看到英文版（SetLocale 依 Accept-Language）：__() 的中文字串沒進 lang/en.json，
 * 英文訪客就會看到中英夾雜。2026-10-23 一次補了 229 條，這支擋住之後新增頁面又漏掉。
 */
class EnglishTranslationCoverageTest extends TestCase
{
    public function test_every_chinese_ui_string_has_an_english_translation(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $missing = [];

        foreach ([app_path(), resource_path('views'), base_path('routes')] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/u", $src, $single);
                preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/u', $src, $double);
                foreach ([...$single[1], ...$double[1]] as $key) {
                    $key = stripslashes($key);
                    if (preg_match('/\p{Han}/u', $key) && ! array_key_exists($key, $en)) {
                        $missing[$key] = str_replace(base_path().'/', '', $file->getPathname());
                    }
                }
            }
        }

        $this->assertSame([], $missing, '這些字串沒有英文翻譯，請補進 lang/en.json');
    }
}
