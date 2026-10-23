// Kolguyev command bridge. Pack this addon in Workbench and load it on the
// dedicated server instead of Conflict PvE. The script does nothing until
// $profile:cbpnk-war/enabled exists.
//
// orders.tsv (website → mod), one line each:
//   WEEK <id>
//   HOME <us|ussr|fia> <x> <z>            player respawn + muster for that side
//   SQUAD <id> <us|ussr|fia> <x> <z> <strength> <tier>
//   VEHICLE <id> <us|ussr|fia> <kind> <prefab>      see CBPNK_WarMotorPool
//   ORDER <id> <squad_id> <us|ussr|fia> <x> <z> <kind> <target soldier, 0> [<x2> <z2>]
//         kinds are the vanilla commanding menu's (Configs/Commanding/Commands.conf), same
//         prefabs, completion radii and forcing: move, move_forced, defend, search, suppress,
//         heal (the squad's medic treats the target soldier), mount (GetIn the target vehicle),
//         dismount, halt (clear waypoints), patrol (two Patrol points in a Cycle, as
//         SCR_PatrolGroupCommand). rest is a move home; recon/sabotage/raid are moves the site
//         judges as operations, walked under HOLD_FIRE like the vanilla combat-mode command.
//         move/move_forced may go on along LEG lines; smoke (SCR_DeploySmokeCoverWaypoint
//         put first, the order under way goes on), follow (AIWaypoint_Follow on the target
//         squad's leader), attack (AIWaypoint_Attack on the enemy the group perceives nearest
//         the point, else search and destroy there)
//         recon is the game's Scout (AIWaypoint_Scout): go there and watch the area; target
//         is how many seconds it holds (SCR_TimedWaypoint.SetHoldingTime)
//         sabotage walks the squad to a relay tower; its sapper plants a real demolition charge
//         (M112, TSh-400g) armed with a timed fuze, and the squad falls back
//   LEG <order_id> <x> <z>                          the next point of a move's route, in order
//   CHARGE <order_id> <plant_s> <fuze_s>            a sabotage's planting time and fuze
//   COMBAT <squad_id> <hold|return|free>            the group's combat mode (SetCombatMode)
//   FORM <squad_id> <auto|wedge|line|column|staggered> <auto|walk|run|sprint>
//         the group's formation and pace: group AI settings of origin COMMANDING
//         (SCR_AIGroupFormationSetting, SCR_AIGroupCharactersMovementSpeedSetting)
//   SOLDIER <id> <squad_id> <role> <aim> <grit> <fieldcraft> <service> [<stance> <pace>]
//         stance auto|stand|crouch|prone and pace auto|walk|run|sprint become the game's AI
//         character settings (SCR_AICharacterStanceSetting, SCR_AICharacterMovementSpeedSetting)
//         of origin COMMANDING; auto removes them
//   FIRE / SUPPLY                                   see CBPNK_WarSupport
// state.tsv (mod → website), rewritten every tick and closed with END:
//   SQUAD <id> <x> <z> <alive> <doing> <hold|return|free>
//         doing: the kind of our order whose waypoint the group is on, idle with none, own when
//         the group follows a waypoint of the game's own
//   SOLDIER <id> dead
//   SOLDIER <id> ok ...                             see CBPNK_WarCondition
//   VEHICLE / FIRE / SUPPLY / HEAL                  see the files above
//   WORLD ...                                       see CBPNK_WarWorld
//   CONTACT <squad_id> <us|ussr|fia> <x> <z> <inf|veh> <seen|heard> <age_s>
//   ORDER <id> acked|done|failed
//   SABOTAGE <order_id> <approach|planting|armed|detonated|disarmed> <seconds to go>
//   END
//
// A WEEK line with no squads stands the island down. A dead body is not replaced;
// the site marks that soldier killed when it reads the SOLDIER dead line.
//
// The rest of the mod: CBPNK_WarLines (typed order lines), CBPNK_WarWaypoints (the vanilla
// waypoints orders are made of), CBPNK_WarCharacters (prefabs by side and role), CBPNK_WarTraits
// (drilled stats and readiness in the game), CBPNK_WarContacts (group perception),
// CBPNK_WarCondition (each man's state), CBPNK_WarMotorPool, CBPNK_WarSupport, CBPNK_WarKills,
// CBPNK_WarWorld, CBPNK_WarAdmins, CBPNK_WarGround (shared helpers).
//
// Coordinates are terrain metres, south-west origin, X east, Z north, 0..12800.
// API names are checked against the vanilla scripts in addons/data (SCR_AIGroup,
// SCR_AICombatComponent, SCR_EntityCatalog, BaseGameMode).

class CBPNK_WarBridge
{
	// Empty vanilla group. Members come from SOLDIER lines only.
	static const ResourceName GROUP_PREFAB = "{000CD338713F2B5A}Prefabs/AI/Groups/Group_Base.et";
	// Real demolition charges: the US block, and the Soviet one the USSR and FIA carry.
	static const ResourceName CHARGE_US = "{33CBDE73AB48172A}Prefabs/Weapons/Explosives/DemoBlock_M112/DemoBlock_M112.et";
	static const ResourceName CHARGE_SOVIET = "{97064F8597F2D7BF}Prefabs/Weapons/Explosives/DemoBlock_TSh400g/DemoBlock_TSh400g.et";
	// The sapper has to be this close to the tower to plant, and the squad falls back this far.
	static const float PLANT_METRES = 15;
	static const float FALL_BACK_METRES = 120;
	// An enemy the group perceives this close to the commander's mark is the one he means.
	static const float ATTACK_PICK_METRES = 60;
	static const string DIR = "$profile:cbpnk-war/";
	// Squad-sized arrival for the site's operations and HQ legs (the commanding menu uses 5 m).
	static const float ARRIVED_METRES = 30;
	static const int SPAWNS_PER_TICK = 4;
	// Vanilla faction respawn points (SCR_SpawnPoint with the faction key set in the prefab).
	static const ResourceName SPAWN_US = "{5A2220DD0C16958B}Prefabs/MP/Spawning/SpawnPoint_US.et";
	static const ResourceName SPAWN_USSR = "{4FEA25583D0975F7}Prefabs/MP/Spawning/SpawnPoint_USSR.et";
	static const ResourceName SPAWN_FIA = "{2B1758F237E4820E}Prefabs/MP/Spawning/SpawnPoint_FIA.et";

	protected static ref map<int, SCR_AIGroup> s_Groups = new map<int, SCR_AIGroup>();
	protected static ref map<int, string> s_Side = new map<int, string>();
	protected static ref map<int, int> s_Strength = new map<int, int>();
	protected static ref map<int, int> s_Tier = new map<int, int>();
	protected static ref map<int, vector> s_LastSeen = new map<int, vector>();
	protected static ref map<int, string> s_OrderStatus = new map<int, string>();
	// The game decides when an order is done: the waypoint we gave and the group carrying it.
	// AIGroup.CompleteWaypoint removes a completed waypoint from the group's list.
	protected static ref map<int, AIWaypoint> s_OrderWaypoint = new map<int, AIWaypoint>();
	protected static ref map<int, SCR_AIGroup> s_OrderGroup = new map<int, SCR_AIGroup>();
	protected static ref map<int, int> s_OrderSquad = new map<int, int>();
	protected static ref map<int, IEntity> s_Soldiers = new map<int, IEntity>();
	protected static ref map<int, int> s_SoldierSquad = new map<int, int>();
	protected static ref map<int, bool> s_Dead = new map<int, bool>();
	protected static ref map<string, IEntity> s_SpawnPoints = new map<string, IEntity>();
	protected static ref map<string, vector> s_SpawnAt = new map<string, vector>();
	// Where the site wants the squad: used when no one is left to lead it (after an extraction).
	protected static ref map<int, vector> s_SquadAt = new map<int, vector>();
	protected static ref map<int, string> s_OrderKind = new map<int, string>();
	// The group's combat mode as the site set it: hold|return|free.
	protected static ref map<int, string> s_Combat = new map<int, string>();

	// Per-soldier AI orders last sent: "<stance> <pace>".
	protected static ref map<int, string> s_Posture = new map<int, string>();
	// Each soldier's role, for the jobs that need one (the sapper plants the charge).
	protected static ref map<int, string> s_Role = new map<int, string>();
	// Sabotage jobs by order: phase, when planting began, the charge once it is in the world.
	protected static ref map<int, string> s_SabPhase = new map<int, string>();
	protected static ref map<int, float> s_SabSince = new map<int, float>();
	protected static ref map<int, IEntity> s_SabCharge = new map<int, IEntity>();
	protected static ref map<int, vector> s_SabTarget = new map<int, vector>();
	protected static ref map<int, float> s_SabPlant = new map<int, float>();
	protected static ref map<int, float> s_SabFuze = new map<int, float>();
	// Group formation and pace last sent: "<formation> <pace>".
	protected static ref map<int, string> s_Form = new map<int, string>();
	// Every waypoint of an order (a route has several); s_OrderWaypoint holds its last.
	protected static ref map<int, ref array<AIWaypoint>> s_OrderLegs = new map<int, ref array<AIWaypoint>>();
	// Where a move ends (its last point).
	protected static ref map<int, vector> s_OrderEnd = new map<int, vector>();
	// A move whose waypoint is gone with the leader farther than this from its end did not arrive.
	static const float SHORT_METRES = 45;

	static void Tick()
	{
		if (!Replication.IsServer())
			return;

		CBPNK_WarAdmins.Tend();

		if (!FileIO.FileExists(DIR + "enabled"))
			return;

		CBPNK_WarBridge bridge = new CBPNK_WarBridge();
		bridge.Pull();
		bridge.Push();
	}

	void Pull()
	{
		FileHandle handle = FileIO.OpenFile(DIR + "orders.tsv", FileMode.READ);
		if (!handle)
			return;

		array<ref CBPNK_WarSoldierWish> wishes = {};
		array<int> squadIds = {};
		array<int> orderIds = {};
		array<ref CBPNK_WarOrderLine> orders = {};
		ref map<int, ref array<vector>> legs = new map<int, ref array<vector>>();
		ref map<int, bool> vehicleIds = new map<int, bool>();
		array<int> fireIds = {};
		array<int> supplyIds = {};
		array<ref array<string>> supplies = {};
		array<int> serviceIds = {};
		array<ref array<string>> services = {};
		bool sawWeek = false;
		string line;
		int guard = 0;
		while (handle.ReadLine(line) > 0 && guard < 1024)
		{
			guard++;
			line.Replace("\r", "");
			array<string> bits = {};
			line.Split("\t", bits, false);
			if (bits.Count() < 2)
				continue;

			if (bits[0] == "WEEK")
				sawWeek = true;
			else if (bits[0] == "HOME" && bits.Count() >= 4)
				EnsureSpawnPoint(bits[1], bits[2].ToFloat(), bits[3].ToFloat());
			else if (bits[0] == "SQUAD" && bits.Count() >= 7)
			{
				squadIds.Insert(bits[1].ToInt());
				EnsureGroup(bits[1].ToInt(), bits[2], bits[3].ToFloat(), bits[4].ToFloat(), bits[5].ToInt(), bits[6].ToInt());
			}
			else if (bits[0] == "ORDER" && bits.Count() >= 6)
			{
				// Applied once the whole file is read: a route's LEG lines follow its ORDER.
				orderIds.Insert(bits[1].ToInt());
				orders.Insert(CBPNK_WarOrderLine.Parse(bits));
			}
			else if (bits[0] == "LEG" && bits.Count() >= 4)
			{
				int legOrder = bits[1].ToInt();
				if (!legs.Contains(legOrder))
					legs.Set(legOrder, new array<vector>());
				legs.Get(legOrder).Insert(Vector(bits[2].ToFloat(), 0, bits[3].ToFloat()));
			}
			else if (bits[0] == "COMBAT" && bits.Count() >= 3)
				ApplyCombat(bits[1].ToInt(), bits[2]);
			else if (bits[0] == "ALERT" && bits.Count() >= 3)
				CBPNK_WarTraits.SetAlert(bits[1].ToInt(), bits[2]);
			else if (bits[0] == "CHARGE" && bits.Count() >= 4)
			{
				s_SabPlant.Set(bits[1].ToInt(), bits[2].ToFloat());
				s_SabFuze.Set(bits[1].ToInt(), bits[3].ToFloat());
			}
			else if (bits[0] == "FORM" && bits.Count() >= 4)
				ApplyForm(bits[1].ToInt(), bits[2], bits[3]);
			else if (bits[0] == "SOLDIER" && bits.Count() >= 7)
				wishes.Insert(CBPNK_WarSoldierWish.Parse(bits));
			else if (bits[0] == "VEHICLE" && bits.Count() >= 5)
			{
				// Delivered at the side's spawn, as at a Conflict vehicle depot; nobody's squad's.
				vehicleIds.Set(bits[1].ToInt(), true);
				if (s_SpawnAt.Contains(bits[2]))
					CBPNK_WarMotorPool.Ensure(bits[1].ToInt(), bits[3], bits[4], s_SpawnAt.Get(bits[2]));
			}
			else if (bits[0] == "FIRE" && bits.Count() >= 5)
			{
				fireIds.Insert(bits[1].ToInt());
				CBPNK_WarSupport.Fire(bits[1].ToInt(), bits[2], bits[3].ToFloat(), bits[4].ToFloat());
			}
			else if (bits[0] == "SUPPLY" && bits.Count() >= 4)
			{
				supplyIds.Insert(bits[1].ToInt());
				supplies.Insert(bits);
			}
			else if (bits[0] == "SERVICE" && bits.Count() >= 3)
			{
				serviceIds.Insert(bits[1].ToInt());
				services.Insert(bits);
			}
		}

		handle.Close();

		foreach (CBPNK_WarOrderLine order : orders)
		{
			order.route = legs.Get(order.id);
			ApplyCommand(order);
		}

		// A truncated read (the host swaps the file with mv, so this is rare) must not
		// stand the island down. Only a file that starts with WEEK is authoritative.
		if (!sawWeek)
			return;

		Reconcile(wishes);
		DropMissingSquads(squadIds);
		ForgetOrders(orderIds);
		CBPNK_WarMotorPool.Retire(vehicleIds);

		foreach (array<string> supply : supplies)
			CBPNK_WarSupport.Resupply(supply[1].ToInt(), LivingBodies(supply[2].ToInt()), supply[3].ToInt());
		foreach (array<string> service : services)
			CBPNK_WarSupport.Service(service[1].ToInt(), CBPNK_WarMotorPool.Get(service[2].ToInt()));
		CBPNK_WarSupport.Forget(fireIds, supplyIds, serviceIds);
	}

	protected array<IEntity> LivingBodies(int squadId)
	{
		array<IEntity> bodies = {};
		foreach (int soldierId, int owner : s_SoldierSquad)
		{
			if (owner != squadId || s_Dead.Contains(soldierId))
				continue;

			IEntity body = s_Soldiers.Get(soldierId);
			if (body && !CBPNK_WarGround.IsDead(body))
				bodies.Insert(body);
		}

		return bodies;
	}

	protected void Reconcile(array<ref CBPNK_WarSoldierWish> wishes)
	{
		map<int, bool> wanted = new map<int, bool>();
		int budget = SPAWNS_PER_TICK;

		foreach (CBPNK_WarSoldierWish wish : wishes)
		{
			if (!wish || wish.id <= 0)
				continue;

			wanted.Set(wish.id, true);
			if (!s_Groups.Get(wish.squadId))
				continue;

			budget = FieldSoldier(wish, budget);
		}

		Retire(wanted);
	}

	protected int FieldSoldier(CBPNK_WarSoldierWish wish, int budget)
	{
		s_Role.Set(wish.id, wish.role);
		// Killed on the island: stays dead until the site stops listing them.
		if (s_Dead.Contains(wish.id))
			return budget;

		IEntity body = s_Soldiers.Get(wish.id);
		if (body)
		{
			if (CBPNK_WarGround.IsDead(body))
			{
				s_Dead.Set(wish.id, true);
				Print(string.Format("CBPNK war: soldier %1 was killed", wish.id), LogLevel.NORMAL);
				return budget;
			}

			// Moved to another squad on the site: leave one group, join the other, the way the
			// game's own recruit and remove commands do it.
			int owner = s_SoldierSquad.Get(wish.id);
			SCR_AIGroup into = s_Groups.Get(wish.squadId);
			if (owner != wish.squadId && into)
			{
				SCR_AIGroup from = s_Groups.Get(owner);
				if (from)
					from.RemoveAIEntityFromGroup(body);
				into.AddAIEntityToGroup(body);
				s_SoldierSquad.Set(wish.id, wish.squadId);
			}

			ApplyPosture(wish, body, into);
			CBPNK_WarTraits.Apply(body, wish, s_Tier.Get(wish.squadId));
			return budget;
		}

		// The body was deleted under us (garbage collection, admin). Count it as a loss.
		if (s_Soldiers.Contains(wish.id))
		{
			s_Soldiers.Remove(wish.id);
			s_Dead.Set(wish.id, true);
			return budget;
		}

		if (budget <= 0)
			return budget;

		body = SpawnSoldier(wish);
		if (!body)
			return budget;

		s_Soldiers.Set(wish.id, body);
		s_SoldierSquad.Set(wish.id, wish.squadId);
		CBPNK_WarTraits.Apply(body, wish, s_Tier.Get(wish.squadId));
		return budget - 1;
	}

	// Soldiers the site no longer lists. A living one was discharged: take the body off
	// the island. A dead one is already recorded: leave the corpse to the garbage system.
	protected void Retire(map<int, bool> wanted)
	{
		array<int> stale = {};
		foreach (int soldierId, IEntity body : s_Soldiers)
		{
			if (!wanted.Contains(soldierId))
				stale.Insert(soldierId);
		}

		foreach (int soldierId, bool dead : s_Dead)
		{
			if (!wanted.Contains(soldierId) && stale.Find(soldierId) < 0)
				stale.Insert(soldierId);
		}

		foreach (int soldierId : stale)
			Forget(soldierId, true);
	}

	protected void Forget(int soldierId, bool deleteLiving)
	{
		IEntity body = s_Soldiers.Get(soldierId);
		if (body && deleteLiving && !CBPNK_WarGround.IsDead(body))
		{
			RplComponent.DeleteRplEntity(body, false);
			Print(string.Format("CBPNK war: soldier %1 left the field", soldierId), LogLevel.NORMAL);
		}

		s_Soldiers.Remove(soldierId);
		s_SoldierSquad.Remove(soldierId);
		s_Posture.Remove(soldierId);
		s_Dead.Remove(soldierId);
		CBPNK_WarTraits.Forget(soldierId);
	}

	protected void DropMissingSquads(array<int> present)
	{
		array<int> gone = {};
		foreach (int squadId, SCR_AIGroup group : s_Groups)
		{
			if (present.Find(squadId) < 0)
				gone.Insert(squadId);
		}

		foreach (int squadId : gone)
			DropSquad(squadId);
	}

	protected void DropSquad(int squadId)
	{
		array<int> members = {};
		foreach (int soldierId, int owner : s_SoldierSquad)
		{
			if (owner == squadId)
				members.Insert(soldierId);
		}

		foreach (int soldierId : members)
			Forget(soldierId, true);

		SCR_AIGroup group = s_Groups.Get(squadId);
		if (group)
			RplComponent.DeleteRplEntity(group, false);

		s_Groups.Remove(squadId);
		s_Side.Remove(squadId);
		s_Strength.Remove(squadId);
		s_Tier.Remove(squadId);
		s_LastSeen.Remove(squadId);
		s_SquadAt.Remove(squadId);
		s_Combat.Remove(squadId);
		s_Form.Remove(squadId);
		CBPNK_WarTraits.ForgetSquad(squadId);
		Print(string.Format("CBPNK war: squad %1 stood down", squadId), LogLevel.NORMAL);
	}

	// The site lists pending and acked moves. One that drops out was finished or
	// replaced there, so stop walking toward it and stop reporting it.
	protected void ForgetOrders(array<int> present)
	{
		array<int> gone = {};
		foreach (int orderId, string status : s_OrderStatus)
		{
			if (present.Find(orderId) < 0)
				gone.Insert(orderId);
		}

		foreach (int orderId : gone)
		{
			s_OrderStatus.Remove(orderId);
			s_OrderWaypoint.Remove(orderId);
			s_OrderGroup.Remove(orderId);
			s_OrderSquad.Remove(orderId);
			s_OrderKind.Remove(orderId);
			s_OrderLegs.Remove(orderId);
			s_OrderEnd.Remove(orderId);
			s_SabPhase.Remove(orderId);
			s_SabSince.Remove(orderId);
			s_SabCharge.Remove(orderId);
			s_SabTarget.Remove(orderId);
			s_SabPlant.Remove(orderId);
			s_SabFuze.Remove(orderId);
		}
	}

	protected void EnsureGroup(int squadId, string side, float x, float z, int strength, int tier)
	{
		s_Side.Set(squadId, side);
		s_Strength.Set(squadId, strength);
		s_Tier.Set(squadId, tier);
		s_SquadAt.Set(squadId, Vector(x, 0, z));
		if (s_Groups.Get(squadId))
			return;

		SCR_AIGroup group = SpawnGroup(CBPNK_WarGround.FactionKey(side), x, z);
		if (!group)
		{
			Print(string.Format("CBPNK war: failed to spawn squad %1 (%2)", squadId, side), LogLevel.ERROR);
			return;
		}

		s_Groups.Set(squadId, group);
		s_LastSeen.Set(squadId, CBPNK_WarGround.Surface(x, z));

		// The group lost every body and Group_Base would delete itself: anyone still listed
		// is dead, and the next SOLDIER lines will refill this new group.
		array<int> orphans = {};
		foreach (int soldierId, int owner : s_SoldierSquad)
		{
			if (owner == squadId && !s_Soldiers.Get(soldierId))
				orphans.Insert(soldierId);
		}
		foreach (int soldierId : orphans)
			s_Dead.Set(soldierId, true);
	}

	// One respawn point per side at its home. Moving the home on the site moves the point.
	protected void EnsureSpawnPoint(string side, float x, float z)
	{
		IEntity existing = s_SpawnPoints.Get(side);
		if (existing && s_SpawnAt.Contains(side) && vector.DistanceXZ(s_SpawnAt.Get(side), Vector(x, 0, z)) < 1)
			return;

		if (existing)
			RplComponent.DeleteRplEntity(existing, false);

		ResourceName prefab = SPAWN_US;
		if (side == "ussr")
			prefab = SPAWN_USSR;
		else if (side == "fia")
			prefab = SPAWN_FIA;

		Resource resource = Resource.Load(prefab);
		if (!resource || !resource.IsValid())
			return;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = CBPNK_WarGround.SafeGround(x, z, 40);

		IEntity point = GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params);
		if (!point)
			return;

		s_SpawnPoints.Set(side, point);
		s_SpawnAt.Set(side, Vector(x, 0, z));
		Print(string.Format("CBPNK war: %1 respawn at %2 %3", side, x, z), LogLevel.NORMAL);
	}

	// One order = one vanilla commanding-menu command for the whole group, issued the way the
	// command classes do it (SCR_WaypointGroupCommand, SCR_StopMovementGroupCommand,
	// SCR_CharacterEntityWaypointGroupCommand, SCR_PatrolGroupCommand): earlier waypoints are
	// replaced, forced commands get the player priority, completion is the waypoint's own.
	protected void ApplyCommand(CBPNK_WarOrderLine order)
	{
		int orderId = order.id;
		int squadId = order.squadId;
		float x = order.x;
		float z = order.z;
		string kind = order.kind;
		int targetId = order.targetId;
		vector second = order.second;
		array<vector> route = order.route;
		if (s_OrderStatus.Contains(orderId))
			return;

		s_OrderSquad.Set(orderId, squadId);
		s_OrderKind.Set(orderId, kind);
		SCR_AIGroup group = s_Groups.Get(squadId);
		if (!group)
		{
			s_OrderStatus.Set(orderId, "failed");
			return;
		}

		// Halt: the vanilla command just clears the group's waypoints.
		if (kind == "halt")
		{
			CBPNK_WarWaypoints.Clear(group);
			s_OrderStatus.Set(orderId, "done");
			return;
		}

		// Look at (SCR_LookAtGroupCommand): every man turns to watch the spot; what the squad
		// is doing goes on.
		if (kind == "watch")
		{
			array<AIAgent> agents = {};
			group.GetAgents(agents);
			vector spot = CBPNK_WarGround.Surface(x, z);
			foreach (AIAgent agent : agents)
			{
				if (!agent)
					continue;
				SCR_AIUtilityComponent utility = SCR_AIUtilityComponent.Cast(agent.FindComponent(SCR_AIUtilityComponent));
				if (utility)
					utility.LookAt(spot);
			}
			s_OrderStatus.Set(orderId, "done");
			return;
		}

		// Smoke goes ahead of whatever the squad is doing and leaves it in place, as the AI's
		// own smoke cover does: the waypoint throws and completes itself.
		if (kind == "smoke")
		{
			AIWaypoint smoke = CBPNK_WarWaypoints.Smoke(SquadPosition(squadId, group));
			if (!smoke)
			{
				s_OrderStatus.Set(orderId, "failed");
				return;
			}
			group.AddWaypointAt(smoke, 0);
			s_OrderWaypoint.Set(orderId, smoke);
			s_OrderGroup.Set(orderId, group);
			s_OrderStatus.Set(orderId, "acked");
			return;
		}

		vector target = CBPNK_WarGround.Surface(x, z);
		AIWaypoint waypoint = null;
		if (kind == "follow")
			waypoint = FollowWaypoint(targetId);
		else if (kind == "attack")
			waypoint = AttackWaypoint(group, target, targetId);
		else if (kind == "mount")
		{
			// The game's "get in" on that vehicle; the group may use it from now on.
			IEntity vehicle = CBPNK_WarMotorPool.Get(targetId);
			if (vehicle)
			{
				CBPNK_WarMotorPool.Register(vehicle, group);
				waypoint = CBPNK_WarMotorPool.BoardWaypoint(vehicle);
			}
		}
		else if (kind == "dismount")
		{
			DismountAll(squadId, group);
			waypoint = CBPNK_WarMotorPool.LeaveWaypoint(SquadPosition(squadId, group));
		}
		else if (kind == "heal")
			waypoint = HealWaypoint(targetId);
		else if (kind == "patrol")
			waypoint = CBPNK_WarWaypoints.Patrol(target, CBPNK_WarGround.Surface(second[0], second[2]));
		else if (kind == "defend")
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.DEFEND, target, 15, true);
		else if (kind == "search")
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.SEARCH, target, 100, true);
		else if (kind == "suppress")
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.SUPPRESS, target, 5, false);
		else if (kind == "move_forced")
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.MOVE, target, 5, true);
		else if (kind == "recon")
			waypoint = CBPNK_WarWaypoints.Scout(target, targetId);
		else if (kind == "sabotage")
		{
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.MOVE, target, PLANT_METRES / 2, true);
			s_SabPhase.Set(orderId, "approach");
			s_SabTarget.Set(orderId, target);
		}
		else if (kind == "raid" || kind == "rest")
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.MOVE, target, ARRIVED_METRES, false);
		else
			waypoint = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.MOVE, target, 5, false);

		if (!waypoint)
		{
			s_OrderStatus.Set(orderId, "failed");
			return;
		}

		CBPNK_WarWaypoints.Clear(group);
		GatherAtLeader(group);
		group.AddWaypoint(waypoint);
		SetDisplacement(group, kind == "follow");
		array<AIWaypoint> all = {waypoint};

		// A route: the same command again at every further point, in order.
		if (route && (kind == "move" || kind == "move_forced"))
		{
			foreach (vector leg : route)
			{
				AIWaypoint next = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.MOVE, CBPNK_WarGround.Surface(leg[0], leg[2]), 5, kind == "move_forced");
				if (!next)
					continue;
				group.AddWaypoint(next);
				all.Insert(next);
				waypoint = next;
			}
		}
		s_OrderLegs.Set(orderId, all);
		// A move, a rush and the way home are judged by where the squad ends up (ShortOfEnd).
		if (kind == "move" || kind == "move_forced" || kind == "rest")
			s_OrderEnd.Set(orderId, waypoint.GetOrigin());

		// Operations walk in holding fire; suppressing needs the guns free (the vanilla suppress
		// command sets FIRE_AT_WILL). Otherwise the squad keeps the combat mode the site set.
		if (kind == "recon" || kind == "sabotage" || kind == "raid")
			SetCombat(group, "hold");
		else if (kind == "suppress")
			SetCombat(group, "free");
		else if (s_Combat.Contains(squadId))
			SetCombat(group, s_Combat.Get(squadId));

		// Done when the game completes the waypoint. Vanilla never completes a defend or a cycle.
		s_OrderWaypoint.Set(orderId, waypoint);
		s_OrderGroup.Set(orderId, group);
		s_OrderStatus.Set(orderId, "acked");
	}

	// Everyone of the squad still aboard gets the game's own dismount message, the way the
	// vehicle combat activity sends it (SCR_AIMessageHandling.SendDismountMessage). The get-out
	// waypoint alone is the leader's: with him on foot and part of the squad in a vehicle, it
	// completed and the men aboard stayed there.
	protected void DismountAll(int squadId, SCR_AIGroup group)
	{
		AICommunicationComponent comms = group.GetCommunicationComponent();
		if (!comms)
			return;

		int n = 0;
		foreach (int soldierId, int owner : s_SoldierSquad)
		{
			if (owner != squadId)
				continue;
			IEntity body = s_Soldiers.Get(soldierId);
			if (!body || CBPNK_WarGround.IsDead(body))
				continue;
			IEntity vehicle = CompartmentAccessComponent.GetVehicleIn(body);
			if (!vehicle)
				continue;
			AIControlComponent control = AIControlComponent.Cast(body.FindComponent(AIControlComponent));
			if (!control)
				continue;
			AIAgent agent = control.GetAIAgent();
			if (!agent)
				continue;
			SCR_AIMessageHandling.SendDismountMessage(agent, vehicle, n, null, comms);
			n++;
		}
		Print(string.Format("CBPNK war: squad %1 dismount, %2 aboard told to get out", squadId, n), LogLevel.NORMAL);
	}

	// The squad's medic treats one man (SCR_CharacterEntityWaypointGroupCommand).
	protected AIWaypoint HealWaypoint(int soldierId)
	{
		IEntity patient = s_Soldiers.Get(soldierId);
		if (!patient || CBPNK_WarGround.IsDead(patient))
			return null;

		return CBPNK_WarWaypoints.OnEntity(CBPNK_WarWaypoints.HEAL, patient, 10, false);
	}

	// One step of every sabotage under way: get the sapper to the tower, give him the time to
	// plant, put a real armed charge in the world and pull the squad back, then watch the charge.
	// It is gone once it has gone off; a charge still there with no fuze was disarmed.
	protected void TendSabotage()
	{
		float now = GetGame().GetWorld().GetWorldTime() / 1000;
		foreach (int orderId, string phase : s_SabPhase)
		{
			int squadId = s_OrderSquad.Get(orderId);
			SCR_AIGroup group = s_Groups.Get(squadId);
			vector target = s_SabTarget.Get(orderId);

			if (phase == "approach" || phase == "planting")
			{
				IEntity sapper = SapperNear(squadId, target, PLANT_METRES);
				if (!sapper)
				{
					s_SabPhase.Set(orderId, "approach");
					continue;
				}
				if (phase == "approach")
				{
					s_SabPhase.Set(orderId, "planting");
					s_SabSince.Set(orderId, now);
					continue;
				}
				float plant = 20;
				if (s_SabPlant.Contains(orderId))
					plant = s_SabPlant.Get(orderId);
				if (now - s_SabSince.Get(orderId) < plant)
					continue;

				IEntity charge = PlantCharge(s_Side.Get(squadId), sapper.GetOrigin(), s_SabFuze.Get(orderId));
				if (!charge)
					continue;
				s_SabCharge.Set(orderId, charge);
				s_SabSince.Set(orderId, now);
				s_SabPhase.Set(orderId, "armed");
				if (group)
					FallBack(orderId, group, squadId, target);
				Print(string.Format("CBPNK war: order %1 charge armed at %2", orderId, target), LogLevel.NORMAL);
			}
			else if (phase == "armed")
			{
				IEntity charge = s_SabCharge.Get(orderId);
				if (!charge || charge.IsDeleted())
				{
					s_SabPhase.Set(orderId, "detonated");
					s_OrderStatus.Set(orderId, "done");
					continue;
				}
				SCR_ExplosiveChargeComponent fuze = SCR_ExplosiveChargeComponent.Cast(charge.FindComponent(SCR_ExplosiveChargeComponent));
				if (fuze && fuze.GetUsedFuzeType() == SCR_EFuzeType.NONE)
				{
					s_SabPhase.Set(orderId, "disarmed");
					s_OrderStatus.Set(orderId, "failed");
				}
			}
		}
	}

	protected IEntity SapperNear(int squadId, vector target, float within)
	{
		foreach (int soldierId, int owner : s_SoldierSquad)
		{
			if (owner != squadId || s_Dead.Contains(soldierId))
				continue;
			string role = s_Role.Get(soldierId);
			if (!role.Contains("sapper"))
				continue;
			IEntity body = s_Soldiers.Get(soldierId);
			if (body && !CBPNK_WarGround.IsDead(body) && vector.DistanceXZ(body.GetOrigin(), target) <= within)
				return body;
		}

		return null;
	}

	// The side's own demolition block, on the ground at the sapper's feet, armed with a timed
	// fuze the way the Game Master arms one (SCR_ExplosiveChargeComponent).
	protected IEntity PlantCharge(string side, vector at, float fuzeSeconds)
	{
		ResourceName prefab = CHARGE_SOVIET;
		if (side == "us")
			prefab = CHARGE_US;
		Resource resource = Resource.Load(prefab);
		if (!resource || !resource.IsValid())
			return null;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = CBPNK_WarGround.Surface(at[0], at[2]);
		IEntity charge = GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params);
		if (!charge)
			return null;

		SCR_ExplosiveChargeComponent fuze = SCR_ExplosiveChargeComponent.Cast(charge.FindComponent(SCR_ExplosiveChargeComponent));
		if (!fuze)
		{
			RplComponent.DeleteRplEntity(charge, false);
			return null;
		}
		if (fuzeSeconds <= 0)
			fuzeSeconds = 45;
		fuze.SetFuzeTime(fuzeSeconds, true);
		fuze.ArmWithTimedFuze(true);
		return charge;
	}

	// Away from the charge, toward home: still the sabotage order, so the squad reads as on it.
	protected void FallBack(int orderId, SCR_AIGroup group, int squadId, vector from)
	{
		vector home = from;
		string side = s_Side.Get(squadId);
		if (s_SpawnAt.Contains(side))
			home = s_SpawnAt.Get(side);
		vector away = home - from;
		away[1] = 0;
		if (away.Length() < 1)
			away = Vector(1, 0, 0);
		away.Normalize();
		vector to = from + away * FALL_BACK_METRES;
		AIWaypoint back = CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.MOVE, CBPNK_WarGround.Surface(to[0], to[2]), 10, true);
		if (!back)
			return;
		CBPNK_WarWaypoints.Clear(group);
		group.AddWaypoint(back);
		array<AIWaypoint> legs = s_OrderLegs.Get(orderId);
		if (legs)
			legs.Insert(back);
	}

	// The group entity does not walk with its men. Before a command it is put where the leader
	// is, as the vanilla follow command does (SCR_FollowGroupCommand): a group whose entity was
	// left far behind regroups "somewhere in the middle, far away".
	protected void GatherAtLeader(SCR_AIGroup group)
	{
		IEntity leader = group.GetLeaderEntity();
		if (!leader || CBPNK_WarGround.IsDead(leader))
			return;

		vector transform[4];
		leader.GetTransform(transform);
		group.SetTransform(transform);
		group.Update();
	}

	// Vanilla commands set how the group spreads around its path (AIGroupMovementComponent): a
	// follow keeps its men displaced behind the leader (1), every other command resets it (0,
	// SCR_WaypointGroupCommand.CheckPreviousWaypoints).
	protected void SetDisplacement(SCR_AIGroup group, bool follow)
	{
		AIGroupMovementComponent movement = AIGroupMovementComponent.Cast(group.FindComponent(AIGroupMovementComponent));
		if (!movement)
			return;

		if (follow)
			movement.SetFormationDisplacement(1);
		else
			movement.SetFormationDisplacement(0);
	}

	// The squad id of a group of ours, 0 if none.
	protected int SquadOf(SCR_AIGroup group)
	{
		foreach (int squadId, SCR_AIGroup mine : s_Groups)
		{
			if (mine == group)
				return squadId;
		}
		return 0;
	}

	// The vanilla follow command on another squad's leader (SCR_FollowGroupCommand).
	protected AIWaypoint FollowWaypoint(int leaderSquadId)
	{
		SCR_AIGroup other = s_Groups.Get(leaderSquadId);
		if (!other)
			return null;

		IEntity leader = other.GetLeaderEntity();
		if (!leader || CBPNK_WarGround.IsDead(leader))
			return null;

		return CBPNK_WarWaypoints.OnEntity(CBPNK_WarWaypoints.FOLLOW, leader, 6, true);
	}

	// AIWaypoint_Attack on one man, as the game's attack waypoint takes one entity: the enemy
	// soldier the commander picked (the site names him), while he lives; else the enemy this group
	// perceives nearest the mark. With neither, search and destroy there, as the game would hunt.
	protected AIWaypoint AttackWaypoint(SCR_AIGroup group, vector at, int enemyId)
	{
		IEntity enemy = s_Soldiers.Get(enemyId);
		if (!enemy || CBPNK_WarGround.IsDead(enemy) || s_Side.Get(s_SoldierSquad.Get(enemyId)) == s_Side.Get(SquadOf(group)))
			enemy = CBPNK_WarContacts.PerceivedNear(group, at, ATTACK_PICK_METRES);
		if (!enemy)
			return CBPNK_WarWaypoints.Make(CBPNK_WarWaypoints.SEARCH, at, 100, true);

		return CBPNK_WarWaypoints.OnEntity(CBPNK_WarWaypoints.ATTACK, enemy, 8, true);
	}

	// Formation and pace for the whole squad: group AI settings of origin COMMANDING, read by the
	// group's move behaviours (SCR_AISetGroupFormation, SCR_AIGroupSetMovementSpeed). Auto
	// removes ours and leaves the AI its own.
	protected void ApplyForm(int squadId, string formation, string pace)
	{
		string wanted = formation + " " + pace;
		if (s_Form.Get(squadId) == wanted)
			return;

		SCR_AIGroup group = s_Groups.Get(squadId);
		if (!group)
			return;

		SCR_AIGroupSettingsComponent settings = SCR_AIGroupSettingsComponent.Cast(group.FindComponent(SCR_AIGroupSettingsComponent));
		if (!settings)
			return;

		settings.RemoveSettingsOfTypeAndOrigin(SCR_AIGroupFormationSettingBase, SCR_EAISettingOrigin.COMMANDING);
		if (formation != "auto")
		{
			SCR_EAIGroupFormation shape = SCR_EAIGroupFormation.Wedge;
			if (formation == "line")
				shape = SCR_EAIGroupFormation.Line;
			else if (formation == "column")
				shape = SCR_EAIGroupFormation.Column;
			else if (formation == "staggered")
				shape = SCR_EAIGroupFormation.StaggeredColumn;
			settings.AddSetting(SCR_AIGroupFormationSetting.Create(SCR_EAISettingOrigin.COMMANDING, shape), false, true);

			// Take it up now, not only on the next move.
			AIFormationComponent shapeNow = AIFormationComponent.Cast(group.FindComponent(AIFormationComponent));
			if (shapeNow)
				shapeNow.SetFormation(typename.EnumToString(SCR_EAIGroupFormation, shape));
		}

		settings.RemoveSettingsOfTypeAndOrigin(SCR_AIGroupCharactersMovementSpeedSettingBase, SCR_EAISettingOrigin.COMMANDING);
		if (pace != "auto")
		{
			EMovementType speed = EMovementType.RUN;
			if (pace == "walk")
				speed = EMovementType.WALK;
			else if (pace == "sprint")
				speed = EMovementType.SPRINT;
			settings.AddSetting(SCR_AIGroupCharactersMovementSpeedSetting.Create(SCR_EAISettingOrigin.COMMANDING, speed), false, true);
		}

		s_Form.Set(squadId, wanted);
	}

	// The squad leader tells one man how to carry himself, the way the game's commanding layer
	// does it: AI settings on his agent (SCR_AICharacterSettingsComponent) of origin COMMANDING.
	// A stance holds up to combat, so he still saves himself from a grenade or a car; a pace
	// holds outside combat, so his AI picks its own speed under fire. Auto removes ours.
	protected void ApplyPosture(CBPNK_WarSoldierWish wish, IEntity body, SCR_AIGroup group)
	{
		string wanted = wish.stance + " " + wish.pace;
		if (s_Posture.Get(wish.id) == wanted)
			return;

		SCR_AICharacterSettingsComponent settings = SCR_AICharacterSettingsComponent.FindOnControlledEntity(body);
		if (!settings)
			return;

		settings.RemoveSettingsOfTypeAndOrigin(SCR_AICharacterStanceSettingBase, SCR_EAISettingOrigin.COMMANDING);
		if (wish.stance != "auto")
		{
			ECharacterStance stance = ECharacterStance.STAND;
			if (wish.stance == "crouch")
				stance = ECharacterStance.CROUCH;
			else if (wish.stance == "prone")
				stance = ECharacterStance.PRONE;
			settings.AddCharacterSetting(SCR_AICharacterStanceSetting.Create(SCR_EAISettingOrigin.COMMANDING, SCR_EAIBehaviorCause.COMBAT, stance), false, true);
		}

		settings.RemoveSettingsOfTypeAndOrigin(SCR_AICharacterMovementSpeedSettingBase, SCR_EAISettingOrigin.COMMANDING);
		if (wish.pace != "auto")
		{
			EMovementType speed = EMovementType.RUN;
			if (wish.pace == "walk")
				speed = EMovementType.WALK;
			else if (wish.pace == "sprint")
				speed = EMovementType.SPRINT;
			settings.AddCharacterSetting(SCR_AICharacterMovementSpeedSetting.Create(SCR_EAISettingOrigin.COMMANDING, SCR_EAIBehaviorCause.DANGER_LOW, speed), false, true);
		}

		s_Posture.Set(wish.id, wanted);
	}

	protected void ApplyCombat(int squadId, string mode)
	{
		if (s_Combat.Get(squadId) == mode)
			return;

		s_Combat.Set(squadId, mode);
		SCR_AIGroup group = s_Groups.Get(squadId);
		if (group)
			SetCombat(group, mode);
	}

	// The vanilla combat-mode commands: hold fire, return fire, fire at will.
	protected void SetCombat(SCR_AIGroup group, string mode)
	{
		SCR_AIGroupUtilityComponent utility = SCR_AIGroupUtilityComponent.Cast(group.FindComponent(SCR_AIGroupUtilityComponent));
		if (!utility)
			return;

		if (mode == "hold")
			utility.SetCombatMode(EAIGroupCombatMode.HOLD_FIRE);
		else if (mode == "return")
			utility.SetCombatMode(EAIGroupCombatMode.RETURN_FIRE);
		else
			utility.SetCombatMode(EAIGroupCombatMode.FIRE_AT_WILL);
	}

	protected string CombatOf(SCR_AIGroup group)
	{
		SCR_AIGroupUtilityComponent utility = SCR_AIGroupUtilityComponent.Cast(group.FindComponent(SCR_AIGroupUtilityComponent));
		if (!utility)
			return "free";

		// The mode the AI actually fights in (it may differ from the one ordered, see
		// SCR_AIGroupUtilityComponent.EvaluateCombatMode).
		EAIGroupCombatMode mode = utility.GetCombatModeActual();
		if (mode == EAIGroupCombatMode.HOLD_FIRE)
			return "hold";
		if (mode == EAIGroupCombatMode.RETURN_FIRE)
			return "return";
		return "free";
	}

	// What the group is really doing: our order whose waypoint is current, idle, or the game's own.
	protected string Doing(SCR_AIGroup group)
	{
		AIWaypoint current = group.GetCurrentWaypoint();
		if (!current)
			return "idle";

		foreach (int orderId, array<AIWaypoint> legs : s_OrderLegs)
		{
			if (legs && legs.Find(current) >= 0 && s_OrderKind.Contains(orderId))
				return s_OrderKind.Get(orderId);
		}
		foreach (int orderId, AIWaypoint waypoint : s_OrderWaypoint)
		{
			if (waypoint == current && s_OrderKind.Contains(orderId))
				return s_OrderKind.Get(orderId);
		}

		return "own";
	}

	protected void Push()
	{
		foreach (int soldierId, IEntity body : s_Soldiers)
		{
			if (body && !s_Dead.Contains(soldierId) && !CBPNK_WarGround.IsDead(body))
				CBPNK_WarTraits.Keep(soldierId, body, s_SoldierSquad.Get(soldierId));
		}

		FileHandle handle = FileIO.OpenFile(DIR + "state.tsv", FileMode.WRITE);
		if (!handle)
			return;

		foreach (int squadId, SCR_AIGroup group : s_Groups)
		{
			if (!group)
				continue;

			vector pos = SquadPosition(squadId, group);
			handle.WriteLine(string.Format("SQUAD\t%1\t%2\t%3\t%4\t%5\t%6", squadId, CBPNK_WarGround.Coord(pos[0]), CBPNK_WarGround.Coord(pos[2]), Living(squadId), Doing(group), CombatOf(group)));
		}

		foreach (int soldierId, bool dead : s_Dead)
			handle.WriteLine(string.Format("SOLDIER\t%1\tdead", soldierId));

		foreach (int soldierId, IEntity body : s_Soldiers)
		{
			if (body && !s_Dead.Contains(soldierId) && !CBPNK_WarGround.IsDead(body))
				handle.WriteLine(CBPNK_WarCondition.Line(soldierId, body));
		}

		CBPNK_WarMotorPool.WriteState(handle);
		CBPNK_WarSupport.WriteState(handle);
		CBPNK_WarKills.WriteState(handle);
		CBPNK_WarWorld.WriteState(handle);

		foreach (int squadId, SCR_AIGroup group : s_Groups)
		{
			if (group)
				CBPNK_WarContacts.Write(handle, squadId, group, s_Side.Get(squadId));
		}

		TendSabotage();
		foreach (int orderId, string phase : s_SabPhase)
		{
			int left = 0;
			if (phase == "planting")
				left = Math.Max(0, s_SabPlant.Get(orderId) - (GetGame().GetWorld().GetWorldTime() / 1000 - s_SabSince.Get(orderId)));
			else if (phase == "armed")
				left = Math.Max(0, s_SabFuze.Get(orderId) - (GetGame().GetWorld().GetWorldTime() / 1000 - s_SabSince.Get(orderId)));
			handle.WriteLine(string.Format("SABOTAGE\t%1\t%2\t%3", orderId, phase, left));
		}

		// A sabotage ends with its charge, not with reaching the tower.
		array<int> arrived = {};
		foreach (int orderId, string status : s_OrderStatus)
		{
			if (status == "acked" && !s_SabPhase.Contains(orderId) && Arrived(orderId))
				arrived.Insert(orderId);
		}
		foreach (int orderId : arrived)
		{
			// The game also takes a move waypoint off the group when the move fails (no path,
			// stuck, stopped: SCR_AIProcessFailedMovementResult). That is not an arrival.
			if (!ShortOfEnd(orderId))
			{
				s_OrderStatus.Set(orderId, "done");
				if (s_OrderEnd.Contains(orderId) && s_OrderGroup.Get(orderId))
					Print(string.Format("CBPNK war: order %1 arrived, leader %2 m from its end", orderId, vector.DistanceXZ(SquadPosition(s_OrderSquad.Get(orderId), s_OrderGroup.Get(orderId)), s_OrderEnd.Get(orderId))), LogLevel.NORMAL);
			}
			else
			{
				s_OrderStatus.Set(orderId, "failed");
				Print(string.Format("CBPNK war: order %1 could not get there", orderId), LogLevel.WARNING);
			}
		}

		foreach (int orderId, string status : s_OrderStatus)
			handle.WriteLine(string.Format("ORDER\t%1\t%2", orderId, status));

		// The host posts this file only when the last line is END, so a half-written
		// file is never sent.
		handle.WriteLine("END");
		handle.Close();
	}

	// The war's soldier in this body (0: none of ours), and the side of his squad.
	static int SoldierOf(IEntity body)
	{
		foreach (int soldierId, IEntity mine : s_Soldiers)
		{
			if (mine == body)
				return soldierId;
		}
		return 0;
	}

	static string SideOfSoldier(int soldierId)
	{
		return s_Side.Get(s_SoldierSquad.Get(soldierId));
	}

	// Whether a move whose waypoint is gone left the squad short of where it was to go.
	protected bool ShortOfEnd(int orderId)
	{
		if (!s_OrderEnd.Contains(orderId) || !s_OrderGroup.Contains(orderId))
			return false;
		SCR_AIGroup group = s_OrderGroup.Get(orderId);
		if (!group)
			return false;
		vector end = s_OrderEnd.Get(orderId);
		vector at = SquadPosition(s_OrderSquad.Get(orderId), group);
		float slack = SHORT_METRES;
		if (s_OrderKind.Get(orderId) == "rest")
			slack = ARRIVED_METRES + SHORT_METRES;
		return vector.DistanceXZ(at, end) > slack;
	}

	// The game's own verdict: AIGroup.CompleteWaypoint takes a finished waypoint off the group.
	// A waypoint entity gone altogether went the same way (we only delete ones we replace, and
	// a replaced order has already left the site's list).
	protected bool Arrived(int orderId)
	{
		if (!s_OrderGroup.Contains(orderId))
			return false;

		SCR_AIGroup group = s_OrderGroup.Get(orderId);
		AIWaypoint waypoint = s_OrderWaypoint.Get(orderId);
		if (!group)
			return false;
		if (!waypoint)
			return true;

		array<AIWaypoint> current = {};
		group.GetWaypoints(current);
		return current.Find(waypoint) < 0;
	}

	// The group entity does not walk with its men; the leader does.
	protected vector SquadPosition(int squadId, SCR_AIGroup group)
	{
		IEntity leader = group.GetLeaderEntity();
		if (leader && !CBPNK_WarGround.IsDead(leader))
		{
			vector pos = leader.GetOrigin();
			s_LastSeen.Set(squadId, pos);
			return pos;
		}

		if (s_LastSeen.Contains(squadId))
			return s_LastSeen.Get(squadId);

		return group.GetOrigin();
	}

	protected int Living(int squadId)
	{
		int alive = 0;
		foreach (int soldierId, int owner : s_SoldierSquad)
		{
			if (owner != squadId || s_Dead.Contains(soldierId))
				continue;

			IEntity body = s_Soldiers.Get(soldierId);
			if (body && !CBPNK_WarGround.IsDead(body))
				alive++;
		}

		return alive;
	}

	protected IEntity SpawnSoldier(CBPNK_WarSoldierWish wish)
	{
		SCR_AIGroup group = s_Groups.Get(wish.squadId);
		if (!group)
			return null;

		string side = "us";
		if (s_Side.Contains(wish.squadId))
			side = s_Side.Get(wish.squadId);

		ResourceName prefab = CBPNK_WarCharacters.Prefab(side, wish.role);
		if (prefab.IsEmpty())
		{
			Print(string.Format("CBPNK war: no %1 %2 prefab", side, wish.role), LogLevel.ERROR);
			return null;
		}

		Resource resource = Resource.Load(prefab);
		if (!resource || !resource.IsValid())
			return null;

		// Every new man, and every man back from the reserve, appears at his side's spawn on
		// free ground and makes his own way to the group, as a player respawns at a base. A
		// squad with no one left is rebuilt there too.
		vector origin = SquadPosition(wish.squadId, group);
		if (s_SpawnAt.Contains(side))
			origin = s_SpawnAt.Get(side);
		else if (s_SquadAt.Contains(wish.squadId))
			origin = s_SquadAt.Get(wish.squadId);
		if (Living(wish.squadId) == 0)
			s_LastSeen.Set(wish.squadId, origin);
		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		int soldierId = wish.id;
		int column = soldierId % 4;
		int row = (soldierId / 4) % 3;
		float east = origin[0] + column * 3 - 4.5;
		float north = origin[2] + row * 3 - 3;
		params.Transform[3] = CBPNK_WarGround.SafeGround(east, north, 25);

		IEntity body = GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params);
		if (!body)
			return null;

		if (!group.AddAIEntityToGroup(body))
		{
			RplComponent.DeleteRplEntity(body, false);
			return null;
		}

		Print(string.Format("CBPNK war: squad %1 fields soldier %2 (%3)", wish.squadId, wish.id, wish.role), LogLevel.NORMAL);
		return body;
	}


	protected SCR_AIGroup SpawnGroup(string factionKey, float x, float z)
	{
		FactionManager manager = GetGame().GetFactionManager();
		if (!manager)
			return null;

		Faction faction = manager.GetFactionByKey(factionKey);
		if (!faction)
			return null;

		Resource resource = Resource.Load(GROUP_PREFAB);
		if (!resource || !resource.IsValid())
			return null;

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = CBPNK_WarGround.SafeGround(x, z, 40);

		SCR_AIGroup group = SCR_AIGroup.Cast(GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params));
		if (!group)
			return null;

		// Keep the squad when every man is down so a reinforcement rejoins the same group.
		group.SetDeleteWhenEmpty(false);
		group.SetFaction(faction);
		return group;
	}

}

modded class SCR_BaseGameMode
{
	protected override void OnGameStart()
	{
		super.OnGameStart();
		if (!Replication.IsServer())
			return;

		GetGame().GetCallqueue().CallLater(CBPNK_WarBridge.Tick, 5000, true);
	}
}
