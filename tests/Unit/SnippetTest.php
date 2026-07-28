<?php

namespace Tests\Unit;

use App\Support\Snippet;
use PHPUnit\Framework\TestCase;

class SnippetTest extends TestCase
{
    public function test_it_drops_the_separator_and_quoted_history(): void
    {
        $body = "Olmaz\n\n________________________________\nGönderen: Muhammed Can <info@payzec.com>\nGönderildi: Sunday\nKonu: Re: Oldu\n\nTamamdır";

        $this->assertSame('Olmaz', Snippet::make($body));
    }

    public function test_it_cuts_at_original_message_marker(): void
    {
        $body = "Yeni cevap metni\n\n----- Orijinal ileti -----\nKimden: X\n";

        $this->assertSame('Yeni cevap metni', Snippet::make($body));
    }

    public function test_it_strips_stray_underscores_when_there_is_no_lead(): void
    {
        // A body that is basically just a separator + quote must not surface a
        // row of underscores.
        $this->assertStringNotContainsString('____', Snippet::make('____________________ hidden'));
    }

    public function test_plain_message_is_untouched(): void
    {
        $this->assertSame('Merhaba nasılsın', Snippet::make('Merhaba nasılsın'));
    }
}
