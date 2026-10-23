<?php

namespace App\Console\Commands;

use App\Support\WarLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The war's log as a timeline: what happened around a place, to a squad or a man, in order.
 *
 *   php artisan war:timeline --since=40m --near=5200,6100 --radius=400
 *   php artisan war:timeline --squad=12 --from="18:30" --to="19:10"
 *   php artisan war:timeline --soldier=312          (his deaths, kills, wounds, orders of his squad)
 *   php artisan war:timeline --kinds=soldier.killed,squad. --json
 */
final class WarTimelineCommand extends Command
{
    protected $signature = 'war:timeline
        {--session= : war session id (default: every session)}
        {--since= : how far back, e.g. 30m, 2h, 1d (default 1h, unless --from)}
        {--from= : start, in --tz time ("18:30", "2026-10-14 18:30")}
        {--to= : end, in --tz time}
        {--near= : x,z: only rows within --radius of this point (rows with no place are left out)}
        {--radius=300 : metres around --near}
        {--squad=* : only rows about these squads (theirs, or naming them)}
        {--soldier=* : only rows about these soldiers (theirs, or naming them as killer/target)}
        {--kinds= : comma-separated kinds or prefixes: order.,soldier.killed,squad.engaged}
        {--side= : us|ussr|fia}
        {--limit=2000 : at most this many rows}
        {--tz=Asia/Jerusalem : the time zone times are read and shown in}
        {--json : one JSON object per line instead of text}';

    protected $description = 'Show the Kolguyev war log as a timeline, filtered by place, squad, soldier and kind';

    public function handle(): int
    {
        $tz = (string) $this->option('tz');
        $query = DB::table(WarLog::TABLE)->orderBy('at')->orderBy('id');

        if ($this->option('session') !== null) {
            $query->where('session_id', (int) $this->option('session'));
        }
        if ($this->option('from') !== null) {
            $query->where('at', '>=', Carbon::parse((string) $this->option('from'), $tz)->utc());
        } else {
            $query->where('at', '>=', now()->sub($this->span((string) ($this->option('since') ?? '1h'))));
        }
        if ($this->option('to') !== null) {
            $query->where('at', '<=', Carbon::parse((string) $this->option('to'), $tz)->utc());
        }
        if ($this->option('side') !== null) {
            $query->where('side', (string) $this->option('side'));
        }

        $squads = array_map('intval', (array) $this->option('squad'));
        if ($squads !== []) {
            $query->where(fn ($q) => $q->whereIn('squad_id', $squads)
                ->orWhere(fn ($q) => $q->whereIn('other_id', $squads)->whereIn('kind', ['order.given', 'run.ordered', 'run.stage', 'soldier.moved'])));
        }
        $soldiers = array_map('intval', (array) $this->option('soldier'));
        if ($soldiers !== []) {
            $query->where(fn ($q) => $q->whereIn('soldier_id', $soldiers)
                ->orWhere(fn ($q) => $q->whereIn('other_id', $soldiers)->whereIn('kind', ['soldier.killed', 'soldier.down', 'contact.new'])));
        }

        $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('kinds')))));
        if ($kinds !== []) {
            $query->where(function ($q) use ($kinds) {
                foreach ($kinds as $kind) {
                    str_ends_with($kind, '.') ? $q->orWhere('kind', 'like', $kind.'%') : $q->orWhere('kind', $kind);
                }
            });
        }

        if ($this->option('near') !== null) {
            $point = array_map('floatval', explode(',', (string) $this->option('near')));
            if (count($point) !== 2) {
                $this->error('--near takes x,z');

                return self::INVALID;
            }
            $r = (float) $this->option('radius');
            [$x, $z] = $point;
            $query->whereBetween('pos_x', [$x - $r, $x + $r])->whereBetween('pos_z', [$z - $r, $z + $r])
                ->whereRaw('(pos_x - ?) * (pos_x - ?) + (pos_z - ?) * (pos_z - ?) <= ?', [$x, $x, $z, $z, $r * $r]);
        }

        $rows = $query->limit((int) $this->option('limit'))->get();
        foreach ($rows as $row) {
            $at = Carbon::parse($row->at, 'UTC')->setTimezone($tz);
            if ($this->option('json')) {
                $this->line(json_encode([...(array) $row, 'at' => $at->toIso8601String(), 'data' => $row->data === null ? null : json_decode($row->data, true)], JSON_UNESCAPED_UNICODE) ?: '');

                continue;
            }
            $tags = array_filter([
                $row->side,
                $row->order_id !== null ? 'order '.$row->order_id : null,
                $row->ref,
            ]);
            $this->line(sprintf('%s  %-18s %s%s', $at->format('H:i:s'), $row->kind, $row->text, $tags === [] ? '' : '  <fg=gray>['.implode(' · ', $tags).']</>'));
        }
        if (! $this->option('json')) {
            $this->line('<fg=gray>'.$rows->count().' rows</>');
        }

        return self::SUCCESS;
    }

    /** "30m", "2h", "1d", "90s", or bare minutes. */
    private function span(string $text): string
    {
        if (preg_match('/^(\d+)\s*([smhd]?)$/', trim($text), $m) !== 1) {
            return '1 hour';
        }

        return $m[1].' '.match ($m[2]) {
            's' => 'seconds',
            'h' => 'hours',
            'd' => 'days',
            default => 'minutes',
        };
    }
}
