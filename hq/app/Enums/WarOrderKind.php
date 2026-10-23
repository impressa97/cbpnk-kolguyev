<?php

namespace App\Enums;

/**
 * What a squad is told to do: the vanilla commanding menu's commands (Configs/Commanding/
 * Commands.conf), issued by the mod with the same waypoint prefabs, completion radii and
 * forcing, plus a few the site builds from them.
 *
 *   move, move_forced   AIWaypoint_Move (5 m); forced takes the player priority and does not
 *                       stop to fight
 *   defend              AIWaypoint_Defend (15 m, forced); never completes
 *   search              AIWaypoint_SearchAndDestroy (100 m, forced)
 *   suppress            AIWaypoint_Suppress_Commanding, guns free
 *   heal                AIWaypoint_Heal on one soldier: the squad's medic treats him
 *   mount / dismount    AIWaypoint_GetIn on the squad vehicle / AIWaypoint_GetOut (forced)
 *   halt                clear the group's waypoints
 *   patrol              two AIWaypoint_Patrol in an AIWaypoint_Cycle (HQ's beats)
 *   rest                a move home
 *   watch               the game's look-at command (SCR_LookAtGroupCommand): every man turns to
 *                       watch the mark; the order under way goes on
 *   smoke               AIWaypoint_DeploySmokeCover put first: smoke between the squad and the
 *                       enemies it perceives; the order under way goes on
 *   follow              AIWaypoint_Follow on another squad's leader (SCR_FollowGroupCommand)
 *   attack              AIWaypoint_Attack on a perceived enemy near the mark, else search there
 *   move, move_forced may carry a route: more points, one waypoint each, in order
 *   recon/sabotage/raid a move under hold fire that the site judges as an operation
 */
enum WarOrderKind: string
{
    case Move = 'move';
    case MoveForced = 'move_forced';
    case Defend = 'defend';
    case Search = 'search';
    case Suppress = 'suppress';
    case Heal = 'heal';
    case Mount = 'mount';
    case Dismount = 'dismount';
    case Halt = 'halt';
    case Patrol = 'patrol';
    case Rest = 'rest';
    case Smoke = 'smoke';
    case Watch = 'watch';
    case Follow = 'follow';
    case Attack = 'attack';
    case Recon = 'recon';
    case Sabotage = 'sabotage';
    case Raid = 'raid';

    public function label(): string
    {
        return match ($this) {
            self::Move => 'Move',
            self::MoveForced => 'Move, ignore contact',
            self::Defend => 'Defend',
            self::Search => 'Search and destroy',
            self::Suppress => 'Suppress',
            self::Heal => 'Treat the wounded',
            self::Mount => 'Board vehicle',
            self::Dismount => 'Dismount',
            self::Halt => 'Halt',
            self::Patrol => 'Patrol',
            self::Rest => 'Go home',
            self::Smoke => 'Smoke',
            self::Watch => 'Look at',
            self::Follow => 'Follow',
            self::Attack => 'Attack',
            self::Recon => 'Recon',
            self::Sabotage => 'Sabotage',
            self::Raid => 'Raid',
        };
    }

    /** Needs a point on the map. */
    public function needsPoint(): bool
    {
        return in_array($this, [self::Move, self::MoveForced, self::Defend, self::Search, self::Suppress, self::Patrol, self::Recon, self::Sabotage, self::Raid, self::Attack, self::Watch], true);
    }

    /** Given on top of the order under way, which goes on. */
    public function keepsOrder(): bool
    {
        return $this === self::Smoke || $this === self::Watch;
    }

    /**
     * The game completes it by itself, so a chained order after it can start. A defend, a
     * patrol cycle and a follow go on until the next order.
     */
    public function endsByItself(): bool
    {
        return ! in_array($this, [self::Defend, self::Patrol, self::Follow], true);
    }

    /** Covert work: the squad holds fire and the site checks the operation on arrival. */
    public function isOperation(): bool
    {
        return in_array($this, [self::Recon, self::Sabotage, self::Raid], true);
    }
}
