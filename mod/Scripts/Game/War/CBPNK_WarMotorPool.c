// Vanilla vehicles drawn from the site's motor pool.
//   orders.tsv  VEHICLE <id> <side> <kind> <prefab ResourceName>
//   state.tsv   VEHICLE <id> <x> <z> <health 0-100> <fuel 0-100> <seats> <crew> <alive 0|1>
// As at a Conflict vehicle depot, a vehicle is delivered at its side's spawn on free ground
// and belongs to no group. A board order names it: the group gets it as a usable vehicle
// (SCR_AIGroupUtilityComponent, what vanilla does when an AI boards) and an AIWaypoint_GetIn
// with SetEntity (the in-game "get in vehicle" command). Leaving uses AIWaypoint_GetOut.
// Once aboard, the group drives on later moves.
// The game's garbage system deletes a vehicle left unused for a while (20 min); ours are kept
// off it, and one that vanishes all the same (not a wreck) is put back where it was last seen
// instead of being reported lost.

class CBPNK_WarMotorPool
{
	static const ResourceName GET_IN_PREFAB = "{712F4795CF8B91C7}Prefabs/AI/Waypoints/AIWaypoint_GetIn.et";
	static const ResourceName GET_OUT_PREFAB = "{C40316EE26846CAB}Prefabs/AI/Waypoints/AIWaypoint_GetOut.et";

	protected static ref map<int, IEntity> s_Vehicles = new map<int, IEntity>();
	protected static ref map<int, string> s_VehicleKind = new map<int, string>();
	protected static ref map<int, vector> s_LastAt = new map<int, vector>();
	protected static ref map<int, bool> s_Wrecked = new map<int, bool>();
	// Vehicles the site let go of while somebody was still in them: removed once empty.
	protected static ref array<IEntity> s_Leaving = {};

	// Spawn or keep the vehicle the site lists, on free ground near the spawn. Returns false
	// when it could not be placed; the next tick tries again.
	static bool Ensure(int vehicleId, string kind, ResourceName prefab, vector spawn)
	{
		s_VehicleKind.Set(vehicleId, kind);
		if (s_Vehicles.Contains(vehicleId))
			return true;

		Resource resource = Resource.Load(prefab);
		if (!resource || !resource.IsValid())
			return false;

		// Room for a truck: clear ground within 80 m of the spawn (or of where a vanished one
		// was last seen), never on top of anyone.
		vector from = spawn;
		if (s_LastAt.Contains(vehicleId))
			from = s_LastAt.Get(vehicleId);
		vector near = Vector(from[0], GetGame().GetWorld().GetSurfaceY(from[0], from[2]), from[2]);
		vector spot;
		if (!SCR_WorldTools.FindEmptyTerrainPosition(spot, near, 80, 6, 3))
			return false;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = spot;

		IEntity vehicle = GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params);
		if (!vehicle)
			return false;

		s_Vehicles.Set(vehicleId, vehicle);
		KeepOffGarbage(vehicle);
		Print(string.Format("CBPNK war: vehicle %1 (%2) delivered at %3", vehicleId, kind, spot), LogLevel.NORMAL);
		return true;
	}

	// Never cleaned up as abandoned: it is the commander's until it burns or he sends it back.
	static void KeepOffGarbage(IEntity vehicle)
	{
		SCR_GarbageSystem garbage = SCR_GarbageSystem.GetByEntityWorld(vehicle);
		if (!garbage)
			return;

		garbage.UpdateBlacklist(vehicle, true);
		if (garbage.IsInserted(vehicle))
			garbage.Withdraw(vehicle);
	}

	static void Register(IEntity vehicle, SCR_AIGroup group)
	{
		if (!vehicle || !group)
			return;

		SCR_AIGroupUtilityComponent utility = SCR_AIGroupUtilityComponent.Cast(group.FindComponent(SCR_AIGroupUtilityComponent));
		if (!utility)
			return;

		IEntity owner;
		SCR_AIVehicleUsageComponent usage = SCR_AIVehicleUsageComponent.FindOnNearestParent(vehicle, owner);
		if (usage)
			utility.AddUsableVehicle(usage);
	}

	// Vehicles the site no longer lists: a wreck is left where it burned, a working one is removed.
	static void Retire(map<int, bool> wanted)
	{
		array<int> stale = {};
		foreach (int vehicleId, IEntity vehicle : s_Vehicles)
		{
			if (!wanted.Contains(vehicleId))
				stale.Insert(vehicleId);
		}

		foreach (int vehicleId : stale)
		{
			IEntity unlisted = s_Vehicles.Get(vehicleId);
			if (unlisted && !IsWrecked(unlisted) && !IsCrewed(unlisted))
				RplComponent.DeleteRplEntity(unlisted, false);
			else if (unlisted && IsWrecked(unlisted))
				Abandon(unlisted);
			else if (unlisted)
			{
				// Somebody is still in it: it is not taken from under them. It goes once empty
				// (the garbage system would have removed it with the men inside).
				s_Leaving.Insert(unlisted);
			}

			s_Vehicles.Remove(vehicleId);
			s_VehicleKind.Remove(vehicleId);
			s_Wrecked.Remove(vehicleId);
			s_LastAt.Remove(vehicleId);
		}

		for (int i = s_Leaving.Count() - 1; i >= 0; i--)
		{
			IEntity leaving = s_Leaving[i];
			if (!leaving)
			{
				s_Leaving.Remove(i);
				continue;
			}
			if (IsWrecked(leaving))
			{
				Abandon(leaving);
				s_Leaving.Remove(i);
			}
			else if (!IsCrewed(leaving))
			{
				RplComponent.DeleteRplEntity(leaving, false);
				s_Leaving.Remove(i);
			}
		}
	}

	// A wreck is the game's again: its garbage system clears it.
	protected static void Abandon(IEntity vehicle)
	{
		SCR_GarbageSystem garbage = SCR_GarbageSystem.GetByEntityWorld(vehicle);
		if (garbage)
		{
			garbage.UpdateBlacklist(vehicle, false);
			garbage.Insert(vehicle);
		}
	}

	// A working vehicle the site listed, by its id.
	static IEntity Get(int vehicleId)
	{
		IEntity vehicle = s_Vehicles.Get(vehicleId);
		if (vehicle && !IsWrecked(vehicle))
			return vehicle;

		return null;
	}

	static AIWaypoint BoardWaypoint(IEntity vehicle)
	{
		Resource resource = Resource.Load(GET_IN_PREFAB);
		if (!resource || !resource.IsValid())
			return null;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = vehicle.GetOrigin();

		SCR_BoardingEntityWaypoint waypoint = SCR_BoardingEntityWaypoint.Cast(GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params));
		if (!waypoint)
			return null;

		waypoint.SetCompletionRadius(15);
		waypoint.SetEntity(vehicle);
		return waypoint;
	}

	static AIWaypoint LeaveWaypoint(vector at)
	{
		Resource resource = Resource.Load(GET_OUT_PREFAB);
		if (!resource || !resource.IsValid())
			return null;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = at;

		return AIWaypoint.Cast(GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params));
	}

	static void WriteState(FileHandle handle)
	{
		// Gone from the world without burning: forget the entity, so the next pull puts the
		// vehicle back where it was last seen; nothing is reported lost.
		array<int> vanished = {};
		foreach (int vehicleId, IEntity vehicle : s_Vehicles)
		{
			if (!vehicle || vehicle.IsDeleted())
				vanished.Insert(vehicleId);
		}
		foreach (int vehicleId : vanished)
		{
			s_Vehicles.Remove(vehicleId);
			Print(string.Format("CBPNK war: vehicle %1 vanished, putting it back", vehicleId), LogLevel.WARNING);
		}

		foreach (int vehicleId, IEntity vehicle : s_Vehicles)
		{
			KeepOffGarbage(vehicle);
			s_LastAt.Set(vehicleId, vehicle.GetOrigin());

			int health = 100;
			DamageManagerComponent damage = DamageManagerComponent.Cast(vehicle.FindComponent(DamageManagerComponent));
			if (damage)
				health = Math.ClampInt(Math.Round(damage.GetHealthScaled() * 100), 0, 100);

			int fuel = 100;
			FuelManagerComponent tank = FuelManagerComponent.Cast(vehicle.FindComponent(FuelManagerComponent));
			if (tank && tank.GetTotalMaxFuel() > 0)
				fuel = Math.ClampInt(Math.Round(tank.GetTotalFuel() / tank.GetTotalMaxFuel() * 100), 0, 100);

			int seats = 0;
			int crew = 0;
			BaseCompartmentManagerComponent compartments = BaseCompartmentManagerComponent.Cast(vehicle.FindComponent(BaseCompartmentManagerComponent));
			if (compartments)
			{
				array<BaseCompartmentSlot> slots = {};
				compartments.GetCompartments(slots);
				seats = slots.Count();
				foreach (BaseCompartmentSlot slot : slots)
				{
					if (slot && slot.GetOccupant())
						crew++;
				}
			}

			int alive = 1;
			if (IsWrecked(vehicle))
				alive = 0;

			vector pos = vehicle.GetOrigin();
			float x = pos[0];
			float z = pos[2];
			handle.WriteLine(string.Format("VEHICLE\t%1\t%2\t%3\t%4\t%5\t%6\t%7\t%8", vehicleId, x.ToString(-1, 2), z.ToString(-1, 2), health, fuel, seats, crew, alive));
		}
	}

	static bool IsWrecked(IEntity vehicle)
	{
		DamageManagerComponent damage = DamageManagerComponent.Cast(vehicle.FindComponent(DamageManagerComponent));
		return damage && damage.IsDestroyed();
	}

	static bool IsCrewed(IEntity vehicle)
	{
		BaseCompartmentManagerComponent compartments = BaseCompartmentManagerComponent.Cast(vehicle.FindComponent(BaseCompartmentManagerComponent));
		if (!compartments)
			return false;

		array<BaseCompartmentSlot> slots = {};
		compartments.GetCompartments(slots);
		foreach (BaseCompartmentSlot slot : slots)
		{
			if (slot && slot.GetOccupant())
				return true;
		}

		return false;
	}
}
