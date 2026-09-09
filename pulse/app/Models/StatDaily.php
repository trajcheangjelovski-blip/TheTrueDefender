<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StatDaily extends Model
{
    protected $table = 'stat_daily';

    public $timestamps = false;

    protected $fillable = ['stat_date', 'views', 'impressions', 'clicks'];

    protected $casts = [
        'stat_date' => 'date',
    ];

    /**
     * Add $by to one of today's counters, creating today's row if needed.
     * Concurrency-safe: increment first, insert only when no row exists yet.
     */
    public static function bump(string $column, int $by = 1): void
    {
        if (! in_array($column, ['views', 'impressions', 'clicks'], true) || $by <= 0) {
            return;
        }

        $today = now()->toDateString();

        if (DB::table('stat_daily')->where('stat_date', $today)->increment($column, $by) === 0) {
            try {
                DB::table('stat_daily')->insert(['stat_date' => $today, $column => $by]);
            } catch (\Throwable $e) {
                // Row was created by a concurrent request between the check and insert.
                DB::table('stat_daily')->where('stat_date', $today)->increment($column, $by);
            }
        }
    }
}
