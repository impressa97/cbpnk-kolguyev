// Deaths and kills of the war's soldiers, as the game reports every death:
// SCR_CharacterControllerComponent.OnDeath hands over the instigator. Each line carries this
// server run's id and a sequence number, so the site takes it once however often it is posted;
// the last 200 of each are kept.
//   state.tsv   KILL <run> <seq> <soldier_id> <victim side>
//                    an enemy of his side killed by one of ours (no friendly fire, no civilians)
//               DEATH <run> <seq> <victim_id> <killer_id|0> <cause> <weapon|-> <metres|-1>
//                     <victim x> <victim z> <killer x|-> <killer z|->
//                    one of ours died. cause: enemy, friendly, self, fire (HQ's mortars, the Game
//                    Master's effects), vehicle (run over, or a vehicle's gun nobody of ours
//                    held), other. weapon: the prefab the killer held, or the vehicle's.

modded class SCR_CharacterControllerComponent
{
	override void OnDeath(IEntity instigatorEntity, notnull Instigator instigator)
	{
		super.OnDeath(instigatorEntity, instigator);
		if (Replication.IsServer())
			CBPNK_WarKills.Record(GetCharacter(), instigatorEntity, instigator);
	}
}

class CBPNK_WarKills
{
	protected static ref array<string> s_Lines = {};
	protected static ref array<string> s_Deaths = {};
	protected static int s_Seq;
	protected static int s_Run;

	static void Record(IEntity victim, IEntity killer, Instigator instigator)
	{
		if (!victim)
			return;
		if (s_Run == 0)
			s_Run = System.GetUnixTime();

		int victimId = CBPNK_WarBridge.SoldierOf(victim);
		int killerId = 0;
		if (killer && killer != victim)
			killerId = CBPNK_WarBridge.SoldierOf(killer);

		if (victimId != 0)
			Death(victim, victimId, killer, killerId, instigator);

		if (killerId == 0)
			return;
		string side = CBPNK_WarGround.SideOfBody(victim);
		if (side == "" || side == CBPNK_WarBridge.SideOfSoldier(killerId))
			return;

		s_Seq++;
		s_Lines.Insert(string.Format("KILL\t%1\t%2\t%3\t%4", s_Run, s_Seq, killerId, side));
		if (s_Lines.Count() > 200)
			s_Lines.RemoveOrdered(0);
		Print(string.Format("CBPNK war: soldier %1 killed a %2 soldier", killerId, side), LogLevel.NORMAL);
	}

	protected static void Death(IEntity victim, int victimId, IEntity killer, int killerId, Instigator instigator)
	{
		// HQ's barrages are the Game Master's mortar effect modules: the module is the killer.
		bool barrage = killer && killerId == 0 && PrefabName(killer).StartsWith("EffectModule");
		string cause = "other";
		if (barrage || (instigator && instigator.GetInstigatorType() == InstigatorType.INSTIGATOR_GM))
			cause = "fire";
		else if (killer == victim)
			cause = "self";
		else if (killerId != 0 && CBPNK_WarBridge.SideOfSoldier(killerId) == CBPNK_WarBridge.SideOfSoldier(victimId))
			cause = "friendly";
		else if (killerId != 0)
			cause = "enemy";
		else if (killer && Vehicle.Cast(killer))
			cause = "vehicle";

		string weapon = "-";
		ChimeraCharacter shooter = ChimeraCharacter.Cast(killer);
		if (shooter)
		{
			BaseWeaponComponent held = CBPNK_WarCondition.CurrentWeapon(shooter);
			if (held && held.GetOwner())
				weapon = PrefabName(held.GetOwner());
		}
		else if (killer && !barrage)
			weapon = PrefabName(killer);

		vector at = victim.GetOrigin();
		string metres = "-1";
		string kx = "-";
		string kz = "-";
		// A barrage's module sits at the aim point: no shooter, no range.
		if (killer && killer != victim && !barrage)
		{
			vector from = killer.GetOrigin();
			metres = Math.Round(vector.Distance(from, at)).ToString();
			kx = from[0].ToString(-1, 1);
			kz = from[2].ToString(-1, 1);
		}

		s_Seq++;
		string line = string.Format("DEATH\t%1\t%2\t%3\t%4\t%5\t%6\t%7", s_Run, s_Seq, victimId, killerId, cause, weapon, metres);
		s_Deaths.Insert(line + "\t" + at[0].ToString(-1, 1) + "\t" + at[2].ToString(-1, 1) + "\t" + kx + "\t" + kz);
		if (s_Deaths.Count() > 200)
			s_Deaths.RemoveOrdered(0);
		Print(string.Format("CBPNK war: soldier %1 died (%2 by %3, %4, %5 m)", victimId, cause, killerId, weapon, metres), LogLevel.NORMAL);
	}

	// The file name of the entity's prefab, without folder and extension: Rifle_AK74_GP25.
	protected static string PrefabName(IEntity entity)
	{
		if (!entity || !entity.GetPrefabData())
			return "-";
		string name = entity.GetPrefabData().GetPrefabName();
		int slash = name.LastIndexOf("/");
		if (slash >= 0)
			name = name.Substring(slash + 1, name.Length() - slash - 1);
		name.Replace(".et", "");
		name.Replace("\t", " ");
		if (name.IsEmpty())
			return "-";
		return name;
	}

	static void WriteState(FileHandle handle)
	{
		foreach (string line : s_Lines)
			handle.WriteLine(line);
		foreach (string line : s_Deaths)
			handle.WriteLine(line);
	}
}
