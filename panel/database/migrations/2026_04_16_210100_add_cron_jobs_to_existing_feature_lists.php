<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_lists')->orderBy('id')->lazyById()->each(function (object $row) {
            $features = json_decode($row->features ?? '[]', true);

            if (! is_array($features) || in_array('cron_jobs', $features, true)) {
                return;
            }

            $features[] = 'cron_jobs';

            DB::table('feature_lists')
                ->where('id', $row->id)
                ->update([
                    'features' => json_encode(array_values(array_unique($features))),
                ]);
        });
    }

    public function down(): void
    {
        DB::table('feature_lists')->orderBy('id')->lazyById()->each(function (object $row) {
            $features = json_decode($row->features ?? '[]', true);

            if (! is_array($features) || ! in_array('cron_jobs', $features, true)) {
                return;
            }

            $features = array_values(array_filter($features, fn ($feature) => $feature !== 'cron_jobs'));

            DB::table('feature_lists')
                ->where('id', $row->id)
                ->update([
                    'features' => json_encode($features),
                ]);
        });
    }
};
