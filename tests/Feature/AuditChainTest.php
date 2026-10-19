<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_audit_events_are_chained_and_immutable(): void
    {
        $user = User::factory()->create();
        $audit = app(AuditService::class);
        $first = $audit->record('auth.login', null, $user, ['source' => 'test']);
        $second = $audit->record('profile.signature.updated', null, $user);
        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertTrue($audit->verifyChain());
        $this->expectException(\LogicException::class);
        AuditEvent::first()->update(['event' => 'tampered']);
    }
}
