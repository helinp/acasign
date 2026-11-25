<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_footer_shows_the_application_version(): void
    {
        config(['app.version' => '1.2.3']);

        $this->get(route('login'))->assertOk()->assertSee('Version 1.2.3');
    }

    public function test_footer_omits_the_version_when_none_is_known(): void
    {
        config(['app.version' => null]);

        $this->get(route('login'))->assertOk()->assertDontSee('Version ');
    }
}
