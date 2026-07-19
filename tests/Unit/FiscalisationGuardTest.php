<?php

namespace Tests\Unit;

use App\Services\Zimra\FiscalisationGuard;
use PHPUnit\Framework\TestCase;

class FiscalisationGuardTest extends TestCase
{
    private FiscalisationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new FiscalisationGuard;
    }

    public function test_blocked_when_master_switch_is_off(): void
    {
        $result = $this->guard->decide(false, 'production', ['production'], [], ['prod-host']);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('disabled', $result['reason']);
    }

    public function test_blocked_for_disallowed_environment(): void
    {
        $result = $this->guard->decide(true, 'local', ['production'], [], ['dev-laptop']);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('local', $result['reason']);
    }

    public function test_blocked_for_unlisted_host(): void
    {
        $result = $this->guard->decide(true, 'production', ['production'], ['prod-host'], ['dev-laptop']);

        $this->assertFalse($result['allowed']);
    }

    public function test_allowed_on_matching_environment_and_host(): void
    {
        $result = $this->guard->decide(true, 'production', ['production'], ['prod-host'], ['prod-host', '10.0.0.5']);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }

    public function test_empty_host_allowlist_skips_host_check(): void
    {
        $result = $this->guard->decide(true, 'production', ['production'], [], ['any-machine']);

        $this->assertTrue($result['allowed']);
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $result = $this->guard->decide(true, 'Production', ['production'], ['PROD-HOST'], ['prod-host']);

        $this->assertTrue($result['allowed']);
    }
}
