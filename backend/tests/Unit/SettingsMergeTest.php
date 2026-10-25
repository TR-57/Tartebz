<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use App\Support\Settings;
use PHPUnit\Framework\TestCase;

class SettingsMergeTest extends TestCase
{
    public function test_saved_values_merge_over_defaults_and_lists_are_replaced_whole(): void
    {
        $default = ['a' => 1, 'nested' => ['x' => 1, 'y' => 2], 'list' => ['BH', 'SA'], 'new' => 'default'];
        $saved = ['a' => 5, 'nested' => ['y' => 9], 'list' => ['KW']];
        $this->assertSame(['a' => 5, 'nested' => ['x' => 1, 'y' => 9], 'list' => ['KW'], 'new' => 'default'], Settings::merge($default, $saved));
    }

    public function test_sanitizer_keeps_simple_inline_html_only(): void
    {
        $this->assertSame('a <b>b</b> c', HtmlSanitizer::inline('a <b onclick="x()">b</b> <script>alert(1)</script>c'));
        $this->assertSame('<a href="/shop">x</a>', HtmlSanitizer::inline('<a href="/shop" style="color:red">x</a>'));
        $this->assertSame('<a>x</a>', HtmlSanitizer::inline('<a href="javascript:alert(1)">x</a>'));
        $this->assertSame('plain', HtmlSanitizer::inline('plain'));
    }
}
