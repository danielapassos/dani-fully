<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            // Placement rows cannot represent an intentionally empty set. This
            // marker distinguishes "publish no attached media for this target"
            // from legacy targets that predate placements and inherit all media.
            $table->boolean('placements_explicit')->default(false)->after('section_sources');
        });

        // Existing targets with rows already use explicit placement semantics;
        // only row-less pre-placement targets need the legacy inherit-all fallback.
        DB::table('post_targets')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('post_media_placements')
                    ->whereColumn('post_media_placements.post_target_id', 'post_targets.id');
            })
            ->update(['placements_explicit' => true]);
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn('placements_explicit');
        });
    }
};
