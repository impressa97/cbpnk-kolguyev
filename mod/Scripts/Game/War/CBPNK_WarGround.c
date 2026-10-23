// What every part of the war mod asks of the island: where the ground is, whether a body is
// dead, and which side a faction or a body is on (us, ussr, fia: the site's names).
class CBPNK_WarGround
{
	// A point on the terrain at x, z.
	static vector Surface(float x, float z)
	{
		vector pos = Vector(x, 0, z);
		pos[1] = GetGame().GetWorld().GetSurfaceY(x, z);
		return pos;
	}

	// Free standing room for one body within radius, the way Conflict places HQ vehicles.
	// Falls back to the terrain height when the area is packed.
	static vector SafeGround(float x, float z, float radius)
	{
		vector wanted = Surface(x, z);
		vector found;
		if (SCR_WorldTools.FindEmptyTerrainPosition(found, wanted, radius, 0.5, 2))
			return found;

		return wanted;
	}

	// A coordinate as the site reads it: metres, two decimals.
	static string Coord(float value)
	{
		return value.ToString(-1, 2);
	}

	static bool IsDead(IEntity body)
	{
		if (!body)
			return true;
		SCR_CharacterDamageManagerComponent damage = SCR_CharacterDamageManagerComponent.Cast(body.FindComponent(SCR_CharacterDamageManagerComponent));
		if (!damage)
			return false;

		return damage.IsDestroyed();
	}

	// The game's faction key for a side.
	static string FactionKey(string side)
	{
		if (side == "ussr")
			return "USSR";
		if (side == "fia")
			return "FIA";
		return "US";
	}

	// The side of a faction key; "" for anyone else (civilians, other factions).
	static string SideOfFaction(string factionKey)
	{
		if (factionKey == "US")
			return "us";
		if (factionKey == "USSR")
			return "ussr";
		if (factionKey == "FIA")
			return "fia";
		return "";
	}

	// The side a body fights for; "" for anyone else.
	static string SideOfBody(IEntity body)
	{
		if (!body)
			return "";
		FactionAffiliationComponent affiliation = FactionAffiliationComponent.Cast(body.FindComponent(FactionAffiliationComponent));
		if (!affiliation || !affiliation.GetAffiliatedFaction())
			return "";

		return SideOfFaction(affiliation.GetAffiliatedFaction().GetFactionKey());
	}

	static SCR_Faction Faction(string side)
	{
		FactionManager manager = GetGame().GetFactionManager();
		if (!manager)
			return null;

		return SCR_Faction.Cast(manager.GetFactionByKey(FactionKey(side)));
	}
}
