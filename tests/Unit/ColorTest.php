<?php

namespace Tests\Unit;

use App\Support\Color;
use PHPUnit\Framework\TestCase;

class ColorTest extends TestCase
{
    public function test_contrast_matches_wcag_reference_values(): void
    {
        $this->assertEqualsWithDelta(21.0, Color::contrast('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(1.0, Color::contrast('#4B2AD6', '#4B2AD6'), 0.01);
        $this->assertGreaterThan(4.5, Color::contrast('#4B2AD6', '#FFFFFF'));
    }

    public function test_darken_and_css_helpers(): void
    {
        $this->assertSame('#808080', Color::darken('#FFFFFF', 0.5));
        $this->assertSame('rgba(75, 42, 214, 0.08)', Color::rgba('#4B2AD6', 0.08));
        $this->assertFalse(Color::isHex('red'));
    }
}
