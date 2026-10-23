// Which vanilla character prefab a soldier of a side and role is made from: the faction's own
// entity catalog (Characters_EntityCatalog_{US,USSR,FIA}.conf), looked up once per side and role.
class CBPNK_WarCharacters
{
	protected static ref map<string, ResourceName> s_Prefabs = new map<string, ResourceName>();

	// The prefab, or empty when the side has no such role.
	static ResourceName Prefab(string side, string role)
	{
		string key = side + "|" + role;
		if (s_Prefabs.Contains(key))
			return s_Prefabs.Get(key);

		ResourceName picked = Find(side, role);
		if (!picked.IsEmpty())
			s_Prefabs.Set(key, picked);
		return picked;
	}

	protected static ResourceName Find(string side, string role)
	{
		ResourceName empty;
		SCR_Faction faction = CBPNK_WarGround.Faction(side);
		if (!faction)
			return empty;

		SCR_EntityCatalog catalog = faction.GetFactionEntityCatalogOfType(EEntityCatalogType.CHARACTER);
		if (!catalog)
			return empty;

		string file = File(side, role);
		if (file == "")
			return empty;

		array<SCR_EntityCatalogEntry> entries = {};
		catalog.GetEntityList(entries);
		foreach (SCR_EntityCatalogEntry entry : entries)
		{
			if (!entry)
				continue;

			ResourceName prefab = entry.GetPrefab();
			if (prefab.IsEmpty())
				continue;

			string path = prefab;
			path.ToLower();
			if (path.EndsWith("/" + file))
				return prefab;
		}

		return empty;
	}

	// Filename stem matches config/reforger.php war.economy.roles and the vanilla catalog entries.
	// The path must end in "/character_us_sniper.et", so a _KLMK or _Variant_1 file never wins.
	protected static string File(string side, string role)
	{
		bool us = side == "us";
		bool ussr = side == "ussr";
		bool fia = side == "fia";
		string stem = "";

		if (role == "rifleman")
			stem = "rifleman";
		else if (role == "team_leader" && us)
			stem = "tl";
		else if (role == "squad_leader")
			stem = "sl";
		else if (role == "senior" && ussr)
			stem = "sr";
		else if (role == "sergeant" && (us || ussr))
			stem = "sergeant";
		else if (role == "platoon")
			stem = "pl";
		else if (role == "officer" && (us || ussr))
			stem = "officer";
		else if (role == "autorifle" && (us || ussr))
			stem = "ar";
		else if (role == "machinegun")
			stem = "mg";
		else if (role == "assistant_mg")
			stem = "amg";
		else if (role == "grenadier" && (us || ussr))
			stem = "gl";
		else if (role == "ammo")
			stem = "ammo";
		else if (role == "antitank" && us)
			stem = "lat";
		else if (role == "antitank" && (ussr || fia))
			stem = "at";
		else if (role == "assistant_at" && (ussr || fia))
			stem = "aat";
		else if (role == "marksman" && us)
			stem = "sniper";
		else if (role == "marksman" && (ussr || fia))
			stem = "sharpshooter";
		else if (role == "spotter" && us)
			stem = "spotter";
		else if (role == "scout")
			stem = "scout";
		else if (role == "scout_radio" && (us || ussr))
			stem = "scout_rto";
		else if (role == "medic")
			stem = "medic";
		else if (role == "radioman")
			stem = "rto";
		else if (role == "sapper")
			stem = "sapper";
		else if (role == "sf" && (us || ussr))
			stem = "sf";
		else if (role == "sf_officer" && (us || ussr))
			stem = "sf_officer";
		else if (role == "sf_leader" && (us || ussr))
			stem = "sf_sl";
		else if (role == "sf_grenadier" && (us || ussr))
			stem = "sf_gl";
		else if (role == "sf_machinegun" && (us || ussr))
			stem = "sf_lmg";
		else if (role == "sf_medic" && (us || ussr))
			stem = "sf_medic";
		else if (role == "sf_radio" && (us || ussr))
			stem = "sf_rto";
		else if (role == "sf_marksman" && (us || ussr))
			stem = "sf_sharpshooter";
		else if (role == "sf_sapper" && (us || ussr))
			stem = "sf_sapper";
		else if (role == "sf_recon" && (us || ussr))
			stem = "sf_s";
		else if (role == "sf_recon_leader" && (us || ussr))
			stem = "sf_sl_s";
		else if (role == "sf_recon_grenadier" && (us || ussr))
			stem = "sf_gl_s";
		else if (role == "sf_recon_medic" && (us || ussr))
			stem = "sf_medic_s";
		else if (role == "sf_recon_radio" && (us || ussr))
			stem = "sf_rto_s";
		else if (role == "sf_recon_marksman" && (us || ussr))
			stem = "sf_sharpshooter_s";
		else if (role == "sf_recon_sapper" && (us || ussr))
			stem = "sf_sapper_s";
		else if (role == "plain" && fia)
			stem = "ac";
		else if (role == "plain_grenadier" && fia)
			stem = "ac_grenadier";
		else if (role == "plain_medic" && fia)
			stem = "ac_medic";
		else if (role == "plain_spotter" && fia)
			stem = "ac_scout";

		if (stem == "")
			return "";

		return "character_" + side + "_" + stem + ".et";
	}
}
