<?php

use App\Models\Workspace;
use Illuminate\Support\Facades\Context;
use Tests\Fixtures\ScopedFixture;

test('scope filters and autofills workspace id', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Context::add('workspace_id', $a->id);

    // workspace_id is auto-filled from context on create
    $row = ScopedFixture::create(['user_id' => $a->owner_id, 'role' => 'member']);
    $this->assertSame($a->id, $row->workspace_id);

    // a row in workspace B is invisible while context is workspace A
    ScopedFixture::withoutGlobalScope('workspace')->create([
        'workspace_id' => $b->id, 'user_id' => $b->owner_id, 'role' => 'member',
    ]);

    $this->assertSame(1, ScopedFixture::count());
});

test('explicitly missing workspace context fails closed while absent worker context stays unscoped', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    foreach ([$a, $b] as $workspace) {
        ScopedFixture::withoutGlobalScope('workspace')->create([
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->owner_id,
            'role' => 'member',
        ]);
    }

    Context::add('workspace_id', null);
    expect(ScopedFixture::count())->toBe(0);

    Context::forget('workspace_id');
    expect(ScopedFixture::count())->toBe(2);

    Context::add('workspace_id', $a->id);
    expect(ScopedFixture::count())->toBe(1);
});
