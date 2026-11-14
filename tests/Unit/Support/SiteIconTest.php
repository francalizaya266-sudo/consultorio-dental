<?php

namespace Tests\Unit\Support;

use App\Support\SiteIcon;
use PHPUnit\Framework\TestCase;

class SiteIconTest extends TestCase
{
    public function test_a_line_icon_takes_the_text_color_and_is_hidden_from_screen_readers(): void
    {
        $svg = SiteIcon::svg('calendar')->toHtml();

        $this->assertStringStartsWith('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertNotSame($svg, SiteIcon::svg('home')->toHtml());
    }

    public function test_the_whatsapp_and_network_logos_are_filled_each_in_its_own_frame(): void
    {
        $this->assertStringStartsWith('<svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">', SiteIcon::svg('whatsapp')->toHtml());
        $this->assertStringStartsWith('<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">', SiteIcon::svg('facebook')->toHtml());
        $this->assertStringNotContainsString('stroke=', SiteIcon::svg('tiktok')->toHtml());
    }

    public function test_an_unknown_name_falls_back_to_the_arrow(): void
    {
        $this->assertSame(SiteIcon::svg('arrow-right')->toHtml(), SiteIcon::svg('no-existe')->toHtml());
    }
}
