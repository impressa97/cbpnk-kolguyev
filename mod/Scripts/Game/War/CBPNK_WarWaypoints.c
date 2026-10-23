// The vanilla waypoints the war's orders are made of, as Configs/Commanding/Commands.conf uses
// them, spawned the way the commanding menu spawns them. No state: the bridge keeps which
// waypoint belongs to which order.
class CBPNK_WarWaypoints
{
	static const ResourceName MOVE = "{750A8D1695BD6998}Prefabs/AI/Waypoints/AIWaypoint_Move.et";
	// Holds the ground: the group takes cover around the point and never completes it.
	static const ResourceName DEFEND = "{93291E72AC23930F}Prefabs/AI/Waypoints/AIWaypoint_Defend.et";
	static const ResourceName SEARCH = "{B3E7B8DC2BAB8ACC}Prefabs/AI/Waypoints/AIWaypoint_SearchAndDestroy.et";
	static const ResourceName SUPPRESS = "{70AAB1ABF7469613}Prefabs/AI/Waypoints/AIWaypoint_Suppress_Commanding.et";
	static const ResourceName HEAL = "{36ED7C150D5BB654}Prefabs/AI/Waypoints/AIWaypoint_Heal.et";
	static const ResourceName PATROL = "{22A875E30470BD4F}Prefabs/AI/Waypoints/AIWaypoint_Patrol.et";
	static const ResourceName CYCLE = "{35BD6541CBB8AC08}Prefabs/AI/Waypoints/AIWaypoint_Cycle.et";
	static const ResourceName SMOKE = "{CE97215CE55CF734}Prefabs/AI/Waypoints/AIWaypoint_DeploySmokeCover.et";
	static const ResourceName FOLLOW = "{A0509D3C4DD4475E}Prefabs/AI/Waypoints/AIWaypoint_Follow.et";
	static const ResourceName ATTACK = "{1B0E3436C30FA211}Prefabs/AI/Waypoints/AIWaypoint_Attack.et";
	static const ResourceName SCOUT = "{A88F0B6CF25BD1DE}Prefabs/AI/Waypoints/AIWaypoint_Scout.et";

	// A waypoint at a point. Forced ones get the player's priority, as the commanding menu's
	// forced commands do.
	static AIWaypoint Make(ResourceName prefab, vector at, float radius, bool forced)
	{
		Resource resource = Resource.Load(prefab);
		if (!resource || !resource.IsValid())
			return null;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = at;
		AIWaypoint waypoint = AIWaypoint.Cast(GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params));
		if (!waypoint)
			return null;

		waypoint.SetCompletionRadius(radius);
		SCR_AIWaypoint scripted = SCR_AIWaypoint.Cast(waypoint);
		if (forced && scripted)
			scripted.SetPriorityLevel(SCR_AIActionBase.PRIORITY_LEVEL_PLAYER);

		return waypoint;
	}

	// A waypoint on an entity: the man to treat (heal), the leader to follow, the enemy to attack.
	static AIWaypoint OnEntity(ResourceName prefab, IEntity entity, float radius, bool forced)
	{
		if (!entity)
			return null;

		SCR_EntityWaypoint waypoint = SCR_EntityWaypoint.Cast(Make(prefab, entity.GetOrigin(), radius, forced));
		if (!waypoint)
			return null;

		waypoint.SetEntity(entity);
		return waypoint;
	}

	// Back and forth between two points until told otherwise (SCR_PatrolGroupCommand).
	static AIWaypoint Patrol(vector a, vector b)
	{
		Resource resource = Resource.Load(CYCLE);
		if (!resource || !resource.IsValid())
			return null;

		AIWaypoint first = Make(PATROL, a, 10, false);
		AIWaypoint last = Make(PATROL, b, 10, false);
		if (!first || !last)
			return null;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = a;
		AIWaypointCycle cycle = AIWaypointCycle.Cast(GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params));
		if (!cycle)
			return null;

		array<AIWaypoint> legs = {first, last};
		cycle.SetWaypoints(legs);
		return cycle;
	}

	// SCR_DeploySmokeCoverWaypoint as the AI uses it: men with smoke throw it between the squad
	// and the enemies it perceives (at most half of them; the rest keep fighting).
	static AIWaypoint Smoke(vector at)
	{
		SCR_DeploySmokeCoverWaypoint smoke = SCR_DeploySmokeCoverWaypoint.Cast(Make(SMOKE, at, 10, true));
		if (!smoke)
			return null;

		smoke.SetMaxGrenadeCount(2);
		smoke.SetSmokeCoverProperties(SCR_AIActivitySmokeCoverFeatureProperties.PROTECT_POS | SCR_AIActivitySmokeCoverFeatureProperties.PROTECT_FROM_CLUSTERS);
		return smoke;
	}

	// The game's Scout: the group goes to the area and watches it for the holding time.
	static AIWaypoint Scout(vector at, int holdSeconds)
	{
		SCR_TimedWaypoint scout = SCR_TimedWaypoint.Cast(Make(SCOUT, at, 20, false));
		if (!scout)
			return null;

		if (holdSeconds > 0)
			scout.SetHoldingTime(holdSeconds);
		return scout;
	}

	// Every waypoint off the group, and out of the world.
	static void Clear(SCR_AIGroup group)
	{
		array<AIWaypoint> current = {};
		group.GetWaypoints(current);
		foreach (AIWaypoint old : current)
		{
			group.RemoveWaypoint(old);
			if (old)
				RplComponent.DeleteRplEntity(old, false);
		}
	}
}
