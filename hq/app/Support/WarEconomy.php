<?php

namespace App\Support;

use App\Enums\WarSide;
use App\Enums\WarSupplyReason;
use App\Models\WarEnlistment;
use App\Models\WarSoldier;
use App\Models\WarSupplyEntry;
use Illuminate\Validation\ValidationException;

/** Prices and caps for the Kolguyev war treasury. Numbers live in config/reforger.php. */
final class WarEconomy
{
    /** Battles count toward experience up to this many. */
    public const BATTLE_CAP = 30;

    public static function sessionDays(): int
    {
        return (int) config('reforger.war.session_days', 7);
    }

    public static function maxSquads(): int
    {
        return (int) config('reforger.war.max_squads', 10);
    }

    /** Supplies to stand up another squad of stock riflemen. The first squad is free. */
    public static function squadCost(): int
    {
        $strength = (int) config('reforger.war.squad_strength', 4);

        return $strength * self::role('rifleman')['cost'];
    }

    /** Soldiers on the island this week: what the server AI cap counts. The reserve at base is off it. */
    public static function fieldStrength(int $sessionId): int
    {
        return WarSoldier::query()
            ->where('session_id', $sessionId)
            ->whereNull('fallen_at')
            ->where('location', 'field')
            ->count();
    }

    /**
     * @return array{id: string, name: string, role: string, cost: int, model: string, prefab: string}
     */
    public static function vehicle(string $id, WarSide $side): array
    {
        $spec = config('reforger.war.vehicles.'.$id);
        $variant = is_array($spec) ? ($spec['sides'][$side->value] ?? null) : null;
        if (! is_array($variant)) {
            throw ValidationException::withMessages([
                'vehicle' => ['That side has no such vehicle.'],
            ]);
        }

        return [
            'id' => $id,
            'name' => (string) $spec['name'],
            'role' => (string) $spec['role'],
            'cost' => (int) $spec['cost'],
            'model' => (string) $variant['model'],
            'prefab' => (string) $variant['prefab'],
        ];
    }

    /**
     * @return list<array{id: string, name: string, role: string, cost: int, model: string, prefab: string}>
     */
    public static function vehicles(WarSide $side): array
    {
        return array_map(
            fn (string $id) => self::vehicle($id, $side),
            array_keys(config('reforger.war.vehicles', [])),
        );
    }

    /**
     * @return array{id: string, name: string, detail: string, cost: int, delay: int, cooldown: int, danger_close: int, magazines: int}
     */
    public static function support(string $id): array
    {
        $spec = config('reforger.war.support.'.$id);
        if (! is_array($spec)) {
            throw ValidationException::withMessages([
                'support' => ['HQ does not offer that.'],
            ]);
        }

        return [
            'id' => $id,
            'name' => (string) $spec['name'],
            'detail' => (string) ($spec['detail'] ?? ''),
            'cost' => (int) $spec['cost'],
            'delay' => (int) ($spec['delay'] ?? 0),
            'cooldown' => (int) ($spec['cooldown'] ?? 0),
            'danger_close' => (int) ($spec['danger_close'] ?? 0),
            'magazines' => (int) ($spec['magazines'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportIds(): array
    {
        return array_keys(config('reforger.war.support', []));
    }

    public static function startingSupplies(): int
    {
        return (int) config('reforger.war.economy.starting_supplies', 80);
    }

    /**
     * Part of what a lost man cost comes back to his commander: refund_killed of it when he is
     * killed, refund_discharged when he is sent home. HQ's own men are HQ's, and a man who cost
     * nothing (the four a commander enlists with) returns nothing. Paid even above the cap.
     */
    public static function refundLoss(WarSoldier $soldier, string $loss): int
    {
        $squad = $soldier->squad;
        if ($squad === null || $squad->managed || $soldier->spent <= 0) {
            return 0;
        }

        $share = (float) config($loss === 'killed' ? 'reforger.war.economy.refund_killed' : 'reforger.war.economy.refund_discharged', 0);
        $amount = (int) floor($soldier->spent * $share);
        if ($amount <= 0) {
            return 0;
        }

        $enlistment = WarEnlistment::query()
            ->where('session_id', $soldier->session_id)
            ->where('commander_id', $squad->commander_id)
            ->lockForUpdate()
            ->first();
        if ($enlistment === null) {
            return 0;
        }

        $enlistment->supplies += $amount;
        $enlistment->save();
        WarSupplyEntry::query()->create([
            'session_id' => $enlistment->session_id,
            'commander_id' => $enlistment->commander_id,
            'amount' => $amount,
            'reason' => WarSupplyReason::Refund,
            'balance' => $enlistment->supplies,
        ]);

        return $amount;
    }

    public static function incomePerHour(): int
    {
        return (int) config('reforger.war.economy.income_per_hour', 40);
    }

    public static function supplyCap(): int
    {
        return (int) config('reforger.war.economy.supply_cap', 400);
    }

    public static function maxStrength(): int
    {
        return (int) config('reforger.war.economy.max_strength', 12);
    }

    public static function maxTier(): int
    {
        return (int) config('reforger.war.economy.max_tier', 2);
    }

    /** Cost of one more stock rifleman, or null when the squad is full. */
    public static function recruitCost(int $strength): ?int
    {
        if ($strength >= self::maxStrength()) {
            return null;
        }

        $role = self::role('rifleman');

        return self::soldierCost('rifleman', $role['aim'], $role['grit'], $role['fieldcraft']);
    }

    public static function statMin(): int
    {
        return (int) config('reforger.war.economy.stat_min', 10);
    }

    public static function statMax(): int
    {
        return (int) config('reforger.war.economy.stat_max', 100);
    }

    public static function pointCost(): int
    {
        return (int) config('reforger.war.economy.point_cost', 1);
    }

    /** What a role does in a fight, for its map symbol. */
    public const ROLE_CLASSES = ['leader', 'mg', 'at', 'marksman', 'medic', 'sapper', 'radio', 'grenadier', 'scout', 'rifle'];

    /**
     * What a man of this role does in a fight, as his symbol shows it: leader (team leader up to
     * officer), mg (machine gun, automatic rifle and their assistants), at (rocket and its
     * assistant), marksman (sniper, sharpshooter, spotter), medic, sapper, radio, grenadier,
     * scout, or rifle for everyone else.
     */
    public static function roleClass(string $role): string
    {
        return match (true) {
            str_contains($role, 'medic') => 'medic',
            str_contains($role, 'sapper') => 'sapper',
            str_contains($role, 'radio') || $role === 'radioman' => 'radio',
            str_contains($role, 'grenadier') => 'grenadier',
            str_contains($role, 'marksman') || str_contains($role, 'spotter') => 'marksman',
            str_contains($role, 'machinegun') || in_array($role, ['autorifle', 'assistant_mg'], true) => 'mg',
            in_array($role, ['antitank', 'assistant_at'], true) => 'at',
            str_contains($role, 'leader') || in_array($role, ['senior', 'sergeant', 'platoon', 'officer', 'sf_officer'], true) => 'leader',
            in_array($role, ['scout', 'sf_recon'], true) => 'scout',
            default => 'rifle',
        };
    }

    /**
     * @return list<string>
     */
    public static function roleIds(): array
    {
        return array_keys(config('reforger.war.economy.roles', []));
    }

    /**
     * @return array{id: string, name: string, task: string, cost: int, min_cost: int, aim: int, grit: int, fieldcraft: int, sides: list<string>, weapons: array{us: ?string, ussr: ?string, fia: ?string}}
     */
    public static function role(string $id): array
    {
        $roles = config('reforger.war.economy.roles', []);
        if (! isset($roles[$id]) || ! is_array($roles[$id])) {
            throw ValidationException::withMessages([
                'role' => ['Unknown soldier role.'],
            ]);
        }

        $role = $roles[$id];
        $sides = is_array($role['sides'] ?? null) ? $role['sides'] : [];

        return [
            'id' => $id,
            'name' => (string) $role['name'],
            'task' => (string) ($role['task'] ?? 'Line'),
            'cost' => (int) $role['cost'],
            'min_cost' => (int) $role['min_cost'],
            'aim' => (int) $role['aim'],
            'grit' => (int) $role['grit'],
            'fieldcraft' => (int) $role['fieldcraft'],
            'sides' => array_keys($sides),
            'weapons' => [
                'us' => isset($sides['us']) ? (string) $sides['us'] : null,
                'ussr' => isset($sides['ussr']) ? (string) $sides['ussr'] : null,
                'fia' => isset($sides['fia']) ? (string) $sides['fia'] : null,
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, task: string, cost: int, min_cost: int, aim: int, grit: int, fieldcraft: int, sides: list<string>, weapons: array{us: ?string, ussr: ?string, fia: ?string}}
     */
    public static function roleForSide(string $id, WarSide $side): array
    {
        $role = self::role($id);
        if (! in_array($side->value, $role['sides'], true)) {
            throw ValidationException::withMessages([
                'role' => ['That side does not field this soldier.'],
            ]);
        }

        return $role;
    }

    /** Catalog price of this soldier. Orders and training write the role's own stats. */
    public static function soldierCost(string $role, int $aim, int $grit, int $fieldcraft): int
    {
        $spec = self::role($role);
        self::assertStat($aim, 'aim');
        self::assertStat($grit, 'grit');
        self::assertStat($fieldcraft, 'fieldcraft');

        $delta = ($aim - $spec['aim']) + ($grit - $spec['grit']) + ($fieldcraft - $spec['fieldcraft']);

        return max($spec['min_cost'], $spec['cost'] + ($delta * self::pointCost()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        return [
            'stat_min' => self::statMin(),
            'stat_max' => self::statMax(),
            'point_cost' => self::pointCost(),
            'stats' => [
                ['id' => 'aim', 'name' => 'Marksmanship'],
                ['id' => 'grit', 'name' => 'Grit'],
                ['id' => 'fieldcraft', 'name' => 'Fieldcraft'],
            ],
            'roles' => array_map(
                fn (string $id) => self::role($id),
                self::roleIds(),
            ),
        ];
    }

    /** Cost to reach the next training tier, or null at veteran. */
    public static function trainCost(int $tier): ?int
    {
        $next = $tier + 1;
        if ($next > self::maxTier()) {
            return null;
        }

        $costs = config('reforger.war.economy.train_costs', []);

        return isset($costs[$next]) ? (int) $costs[$next] : null;
    }

    public static function tierName(int $tier): string
    {
        return (string) config('reforger.war.economy.tiers.'.$tier.'.name', 'Green');
    }

    public static function serviceCap(): int
    {
        return (int) config('reforger.war.economy.service_cap', 24);
    }

    /**
     * Metres east and north of the squad flag. Living soldiers stand in ranks of four.
     *
     * @return array{0: float, 1: float}
     */
    public static function formationOffset(int $index): array
    {
        $spacing = 28.0;
        $col = $index % 4;
        $row = intdiv(max(0, $index), 4);

        return [($col - 1.5) * $spacing, (1 - $row) * $spacing];
    }

    /** Hours served add this much to each stat. The bonus is not for sale. */
    /**
     * What a man has learnt on the island: his hours (up to service_cap), every fight he came
     * through, and three for every enemy he killed.
     */
    public static function experience(WarSoldier $soldier): int
    {
        return min(max(0, $soldier->service), self::serviceCap()) + min(max(0, $soldier->battles), self::BATTLE_CAP) + 3 * max(0, $soldier->kills);
    }

    /**
     * The game's AI skill level a man fights at (the mod sets the same): his aim, plus 10 a
     * squad training tier; veteran from 60, expert from 80, never under regular (vanilla's
     * default for a soldier). Prefabs the game itself makes better keep their own.
     */
    public static function aiSkill(WarSoldier $soldier, int $tier): string
    {
        $score = self::effectiveStat($soldier->aim, self::experience($soldier)) + 10 * $tier;

        return $score >= 80 ? 'expert' : ($score >= 60 ? 'veteran' : 'regular');
    }

    /** What drilling one of a man's stats once more costs, or null when it is at the top. */
    public static function drillCost(WarSoldier $soldier, string $stat): ?int
    {
        $bought = (int) $soldier->{$stat};
        if ($bought >= self::statMax()) {
            return null;
        }
        // Every role starts at 40 endurance; aim, grit and fieldcraft come from the role.
        $base = $stat === 'endurance' ? 40 : (int) self::role($soldier->role)[$stat];
        $step = max(1, (int) config('reforger.war.economy.drill_step', 10));
        $ranks = intdiv(max(0, $bought - $base), $step);

        return (int) config('reforger.war.economy.drill_cost', 20) + $ranks * (int) config('reforger.war.economy.drill_rank', 10);
    }

    public static function effectiveStat(int $bought, int $experience): int
    {
        return min(self::statMax(), $bought + max(0, $experience));
    }

    /**
     * @param  list<string>  $taken
     */
    public static function unusedName(string $side, array $taken): string
    {
        $used = array_map(static fn (string $name) => mb_strtolower($name), $taken);
        $pool = config('reforger.war.economy.names.'.$side, []);
        if (! is_array($pool)) {
            $pool = [];
        }

        foreach ($pool as $name) {
            $name = (string) $name;
            if (! in_array(mb_strtolower($name), $used, true)) {
                return $name;
            }
        }

        return 'Recruit '.(count($taken) + 1);
    }

    public static function assertPerson(string $name, string $note): void
    {
        $name = trim($name);
        if (! preg_match("/^[\\p{L}][\\p{L}\\p{N}\\p{M}\\s'.-]{1,23}$/u", $name)) {
            throw ValidationException::withMessages([
                'name' => ['Give the soldier a name, two to twenty-four letters.'],
            ]);
        }

        if (mb_strlen(trim($note)) > 80) {
            throw ValidationException::withMessages([
                'note' => ['Keep the note to 80 characters.'],
            ]);
        }
    }

    private static function assertStat(int $value, string $field): void
    {
        if ($value < self::statMin() || $value > self::statMax()) {
            throw ValidationException::withMessages([
                $field => ['Stat must be between '.self::statMin().' and '.self::statMax().'.'],
            ]);
        }
    }
}
