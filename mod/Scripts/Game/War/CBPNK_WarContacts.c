// What a squad knows about the enemy, from its group perception, for state.tsv:
//   CONTACT <squad_id> <us|ussr|fia> <x> <z> <inf|veh> <seen|heard> <age_s> <heading|-1> <soldier|0>
// Seen is identified, heard is detected. Lost, destroyed and disarmed targets are left out, as
// are civilians and friendlies. Heading: where a man we see looks (or a vehicle heads). Soldier:
// which of the war's soldiers it is (0: a vehicle or a stranger), so each man is one contact.
class CBPNK_WarContacts
{
	// Enemies a squad reports. The site shows only these.
	static const int PER_SQUAD = 16;

	static void Write(FileHandle handle, int squadId, SCR_AIGroup group, string ownSide)
	{
		SCR_AIGroupUtilityComponent utility = SCR_AIGroupUtilityComponent.Cast(group.FindComponent(SCR_AIGroupUtilityComponent));
		if (!utility || !utility.m_Perception)
			return;

		PerceptionManager perception = GetGame().GetPerceptionManager();
		if (!perception)
			return;

		float now = perception.GetTime();
		int written = 0;
		foreach (SCR_AITargetInfo target : utility.m_Perception.m_aTargets)
		{
			if (!target || written >= PER_SQUAD || !target.m_Faction)
				continue;

			string how = "";
			if (target.m_eCategory == EAITargetInfoCategory.IDENTIFIED)
				how = "seen";
			else if (target.m_eCategory == EAITargetInfoCategory.DETECTED)
				how = "heard";
			if (how == "")
				continue;

			string side = CBPNK_WarGround.SideOfFaction(target.m_Faction.GetFactionKey());
			if (side == "" || side == ownSide)
				continue;

			string kind = "inf";
			if (target.m_Entity && Vehicle.Cast(target.m_Entity))
				kind = "veh";

			int age = Math.Max(0, Math.Round(now - target.m_fTimestamp));
			vector pos = target.m_vWorldPos;
			int heading = -1;
			if (how == "seen" && target.m_Entity)
				heading = CBPNK_WarCondition.Heading(target.m_Entity);
			int who = 0;
			if (target.m_Entity)
				who = CBPNK_WarBridge.SoldierOf(target.m_Entity);
			handle.WriteLine(string.Format("CONTACT\t%1\t%2\t%3\t%4\t%5\t%6\t%7\t%8\t%9", squadId, side, CBPNK_WarGround.Coord(pos[0]), CBPNK_WarGround.Coord(pos[2]), kind, how, age, heading, who));
			written++;
		}
	}

	// The enemy this group perceives (seen or heard) nearest a point, within a distance.
	static IEntity PerceivedNear(SCR_AIGroup group, vector at, float within)
	{
		SCR_AIGroupUtilityComponent utility = SCR_AIGroupUtilityComponent.Cast(group.FindComponent(SCR_AIGroupUtilityComponent));
		if (!utility || !utility.m_Perception)
			return null;

		IEntity best;
		float bestDistance = within;
		foreach (SCR_AITargetInfo target : utility.m_Perception.m_aTargets)
		{
			if (!target || !target.m_Entity)
				continue;
			if (target.m_eCategory != EAITargetInfoCategory.IDENTIFIED && target.m_eCategory != EAITargetInfoCategory.DETECTED)
				continue;

			float d = vector.DistanceXZ(target.m_vWorldPos, at);
			if (d <= bestDistance)
			{
				best = target.m_Entity;
				bestDistance = d;
			}
		}

		return best;
	}
}
