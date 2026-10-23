// HQ support the site has paid for, carried out with vanilla pieces.
//   orders.tsv  FIRE <id> <he_small|he_medium|smoke|illum> <x> <z>
//               SUPPLY <id> <squad_id> <magazines>
//               SERVICE <id> <vehicle_id>
//   state.tsv   FIRE <id> done|failed
//               SUPPLY <id> done|failed
//               SERVICE <id> done|failed
// Fire missions spawn the Game Master effect modules (off-map mortar barrage, smoke screen,
// illumination round); they execute on init on the server. A resupply tops each living
// soldier up to N spare magazines of the weapon in hand. Wounds are the game's business:
// medics and the heal command treat them. A service refuels and repairs a vehicle.

class CBPNK_WarSupport
{
	static const ResourceName FIRE_HE_SMALL = "{5D48E2F7DB0C3714}PrefabsEditable/EffectsModules/Mortar/EffectModule_Zoned_MortarBarrage_Small.et";
	static const ResourceName FIRE_HE_MEDIUM = "{E7B7D4467E9A82DD}PrefabsEditable/EffectsModules/Mortar/EffectModule_Zoned_MortarBarrage_Medium.et";
	static const ResourceName FIRE_SMOKE = "{2FDF3089DF6EC422}PrefabsEditable/EffectsModules/Mortar/Smoke/EffectModule_Zoned_MortarBarrage_Smoke_Medium.et";
	static const ResourceName FIRE_ILLUM = "{AA7F04F403FD2E00}PrefabsEditable/EffectsModules/Flares/EffectModule_Mortar_Flare_Illumination.et";

	protected static ref map<int, string> s_Fire = new map<int, string>();
	protected static ref map<int, string> s_Supply = new map<int, string>();
	protected static ref map<int, string> s_Service = new map<int, string>();

	static void Fire(int missionId, string kind, float x, float z)
	{
		if (s_Fire.Contains(missionId))
			return;

		ResourceName prefab = FIRE_HE_SMALL;
		if (kind == "he_medium")
			prefab = FIRE_HE_MEDIUM;
		else if (kind == "smoke")
			prefab = FIRE_SMOKE;
		else if (kind == "illum")
			prefab = FIRE_ILLUM;

		Resource resource = Resource.Load(prefab);
		if (!resource || !resource.IsValid())
		{
			s_Fire.Set(missionId, "failed");
			return;
		}

		EntitySpawnParams params = new EntitySpawnParams();
		params.TransformMode = ETransformMode.WORLD;
		params.Transform[3] = Vector(x, GetGame().GetWorld().GetSurfaceY(x, z), z);

		IEntity module = GetGame().SpawnEntityPrefab(resource, GetGame().GetWorld(), params);
		if (module)
		{
			s_Fire.Set(missionId, "done");
			Print(string.Format("CBPNK war: HQ fire mission %1 (%2) at %3 %4", missionId, kind, x, z), LogLevel.NORMAL);
		}
		else
			s_Fire.Set(missionId, "failed");
	}

	static void Resupply(int supplyId, array<IEntity> bodies, int magazines)
	{
		if (s_Supply.Contains(supplyId))
			return;

		foreach (IEntity body : bodies)
		{
			ChimeraCharacter character = ChimeraCharacter.Cast(body);
			if (!character)
				continue;

			BaseWeaponComponent weapon = CBPNK_WarCondition.CurrentWeapon(character);
			SCR_InventoryStorageManagerComponent inventory = SCR_InventoryStorageManagerComponent.Cast(character.FindComponent(SCR_InventoryStorageManagerComponent));
			if (!weapon || !inventory)
				continue;

			BaseMagazineComponent magazine = weapon.GetCurrentMagazine();
			if (!magazine || !magazine.GetOwner() || !magazine.GetOwner().GetPrefabData())
				continue;

			ResourceName prefab = magazine.GetOwner().GetPrefabData().GetPrefabName();
			int missing = magazines + CBPNK_WarTraits.ExtraMagazines(CBPNK_WarBridge.SoldierOf(body)) - inventory.GetMagazineCountByWeapon(weapon);
			for (int i = 0; i < missing; i++)
			{
				if (!inventory.TrySpawnPrefabToStorage(prefab))
					break;
			}
		}

		s_Supply.Set(supplyId, "done");
	}

	// Refuel and repair one vehicle as the Game Master's refuel and heal actions do: every fuel
	// node to its maximum, SCR_DamageManagerComponent.FullHeal. A wreck cannot be serviced.
	static void Service(int serviceId, IEntity vehicle)
	{
		if (s_Service.Contains(serviceId))
			return;
		if (!vehicle)
		{
			s_Service.Set(serviceId, "failed");
			return;
		}

		SCR_DamageManagerComponent damage = SCR_DamageManagerComponent.Cast(vehicle.FindComponent(SCR_DamageManagerComponent));
		if (damage && damage.GetState() == EDamageState.DESTROYED)
		{
			s_Service.Set(serviceId, "failed");
			return;
		}
		if (damage)
			damage.FullHeal();

		FuelManagerComponent tank = FuelManagerComponent.Cast(vehicle.FindComponent(FuelManagerComponent));
		if (tank)
		{
			array<BaseFuelNode> nodes = {};
			tank.GetFuelNodesList(nodes);
			foreach (BaseFuelNode node : nodes)
				node.SetFuel(node.GetMaxFuel());
		}

		s_Service.Set(serviceId, "done");
		Print(string.Format("CBPNK war: service %1 refuelled and repaired %2", serviceId, vehicle), LogLevel.NORMAL);
	}

	static void Forget(array<int> fireIds, array<int> supplyIds, array<int> serviceIds)
	{
		array<int> gone = {};
		foreach (int id, string status : s_Fire)
		{
			if (fireIds.Find(id) < 0)
				gone.Insert(id);
		}
		foreach (int id : gone)
			s_Fire.Remove(id);

		gone.Clear();
		foreach (int id, string status : s_Supply)
		{
			if (supplyIds.Find(id) < 0)
				gone.Insert(id);
		}
		foreach (int id : gone)
			s_Supply.Remove(id);

		gone.Clear();
		foreach (int id, string status : s_Service)
		{
			if (serviceIds.Find(id) < 0)
				gone.Insert(id);
		}
		foreach (int id : gone)
			s_Service.Remove(id);
	}

	static void WriteState(FileHandle handle)
	{
		foreach (int id, string status : s_Fire)
			handle.WriteLine(string.Format("FIRE\t%1\t%2", id, status));

		foreach (int id, string status : s_Supply)
			handle.WriteLine(string.Format("SUPPLY\t%1\t%2", id, status));

		foreach (int id, string status : s_Service)
			handle.WriteLine(string.Format("SERVICE\t%1\t%2", id, status));

	}
}
