// What a soldier's drilled stats and his squad's readiness do in the game. Set when he is fielded
// or his stats change (Apply), kept up every tick as he changes weapons, tires and the AI changes
// behaviour (Keep).
//   aim         the game's AI skill (its aiming error) and his weapon's recoil
//   fieldcraft  the perception factor: how soon and how far he spots
//   grit        composure (never taken by surprise) and a slower bleed
//   endurance   breath back on the move, and spare magazines over the squad's (Resupply)
//   readiness   the squad's alert holds each man's behaviour threat at vigilant or alerted
class CBPNK_WarTraits
{
	// The threat a behaviour holds the man at, as vanilla's dangerous move-and-investigate does
	// (SCR_AIMoveBehavior): just over the vigilant and the alerted thresholds of SCR_AIThreatSystem.
	static const float THREAT_VIGILANT = 0.06;
	static const float THREAT_ALERTED = 0.34;

	// How ready each squad is for contact: normal, alert (vigilant) or ready (alerted).
	protected static ref map<int, string> s_Alert = new map<int, string>();
	// Men steady enough to stay vigilant by themselves (grit).
	protected static ref map<int, bool> s_Composed = new map<int, bool>();
	protected static ref map<int, int> s_Aim = new map<int, int>();
	protected static ref map<int, int> s_Endurance = new map<int, int>();
	// The weapon his recoil was last eased on, and whether an easing was refused (said once).
	protected static ref map<int, IEntity> s_RecoilOn = new map<int, IEntity>();
	protected static ref map<int, bool> s_RecoilFailed = new map<int, bool>();
	// What the game gives each body by itself, read the first time: the prefab's AI skill and
	// his own bleeding rate.
	protected static ref map<int, EAISkill> s_BaseSkill = new map<int, EAISkill>();
	protected static ref map<int, float> s_BaseBleed = new map<int, float>();

	static void SetAlert(int squadId, string level)
	{
		s_Alert.Set(squadId, level);
	}

	// The game's own AI skill levels (SCR_AICombatComponent: they set the aiming error) and its
	// perception factor (how fast they spot), earned on the site. Never worse than the prefab
	// the game made him from: a rifleman is REGULAR as in vanilla, a sharpshooter keeps his
	// prefab's EXPERT. Aim plus 10 a training tier: VETERAN from 60, EXPERT from 80. Mirrors
	// WarEconomy::aiSkill.
	static void Apply(IEntity body, CBPNK_WarSoldierWish wish, int tier)
	{
		SCR_AICombatComponent combat = SCR_AICombatComponent.Cast(body.FindComponent(SCR_AICombatComponent));
		if (!combat)
			return;
		if (!s_BaseSkill.Contains(wish.id))
			s_BaseSkill.Set(wish.id, combat.GetAISkill());

		int score = wish.aim + tier * 10;
		EAISkill skill = EAISkill.REGULAR;
		if (score >= 80)
			skill = EAISkill.EXPERT;
		else if (score >= 60)
			skill = EAISkill.VETERAN;
		EAISkill base = s_BaseSkill.Get(wish.id);
		if (base > skill)
			skill = base;

		if (combat.GetAISkill() != skill)
			combat.SetAISkill(skill);

		// Vanilla is 1.0 and nobody is worse: fieldcraft over 40 and training spot sooner and farther.
		float perception = Math.Clamp(1.0 + Math.Max(0, wish.fieldcraft - 40) * 0.012 + tier * 0.05, 1.0, 1.8);
		if (combat.GetPerceptionFactor() != perception)
			combat.SetPerceptionFactor(perception);

		// Grit: a steady man is never taken by surprise, he stays vigilant on his own, and he
		// bleeds slower (the game's per-character bleeding rate, as Game Master sets it).
		s_Composed.Set(wish.id, wish.grit >= 70);
		SCR_CharacterDamageManagerComponent damage = SCR_CharacterDamageManagerComponent.Cast(body.FindComponent(SCR_CharacterDamageManagerComponent));
		if (damage)
		{
			if (!s_BaseBleed.Contains(wish.id))
				s_BaseBleed.Set(wish.id, damage.GetBleedingScale());
			float bleed = s_BaseBleed.Get(wish.id) * (1 - 0.5 * Math.Clamp((wish.grit - 40) / 60.0, 0, 1));
			if (damage.GetBleedingScale() != bleed)
				damage.SetBleedingScale(bleed, true);
		}
		s_Aim.Set(wish.id, wish.aim);
		s_Endurance.Set(wish.id, wish.endurance);
	}

	// Every tick, for each living man of the war.
	static void Keep(int soldierId, IEntity body, int squadId)
	{
		KeepAlert(soldierId, body, squadId);
		KeepRecoil(soldierId, body);
		KeepBreath(soldierId, body);
	}

	// Spare magazines a man carries over the squad's resupply: endurance, one per 20 over 40.
	static int ExtraMagazines(int soldierId)
	{
		if (soldierId == 0 || !s_Endurance.Contains(soldierId))
			return 0;
		return Math.ClampInt((s_Endurance.Get(soldierId) - 40) / 20, 0, 3);
	}

	// A body gone: the next one is read afresh.
	static void Forget(int soldierId)
	{
		s_Composed.Remove(soldierId);
		s_Aim.Remove(soldierId);
		s_Endurance.Remove(soldierId);
		s_RecoilOn.Remove(soldierId);
		s_RecoilFailed.Remove(soldierId);
		s_BaseSkill.Remove(soldierId);
		s_BaseBleed.Remove(soldierId);
	}

	static void ForgetSquad(int squadId)
	{
		s_Alert.Remove(squadId);
	}

	// The squad's alert holds his current behaviour's threat at vigilant or alerted (perception
	// up, no surprise delay; alerted also moves crouched), the way the game's own dangerous moves
	// do; a composed man is vigilant even when the squad is not. Our floor is taken back off when
	// the alert ends; the game's own threat is never touched.
	protected static void KeepAlert(int soldierId, IEntity body, int squadId)
	{
		string alert = s_Alert.Get(squadId);
		float floor = 0;
		if (alert == "ready")
			floor = THREAT_ALERTED;
		else if (alert == "alert" || s_Composed.Get(soldierId))
			floor = THREAT_VIGILANT;

		AIControlComponent control = AIControlComponent.Cast(body.FindComponent(AIControlComponent));
		if (!control || !control.GetControlAIAgent())
			return;
		SCR_AIUtilityComponent utility = SCR_AIUtilityComponent.Cast(control.GetControlAIAgent().FindComponent(SCR_AIUtilityComponent));
		if (!utility || !utility.m_CurrentBehavior)
			return;
		SCR_AIBehaviorBase behavior = utility.m_CurrentBehavior;
		if (behavior.m_fThreat < floor)
			behavior.m_fThreat = floor;
		else if (behavior.m_fThreat > floor && (behavior.m_fThreat == THREAT_VIGILANT || behavior.m_fThreat == THREAT_ALERTED))
			behavior.m_fThreat = floor;
	}

	// Aim: his weapon kicks less (the weapon's recoil factors, as an attachment sets them), on
	// every weapon he takes up.
	protected static void KeepRecoil(int soldierId, IEntity body)
	{
		ChimeraCharacter character = ChimeraCharacter.Cast(body);
		if (!character)
			return;
		BaseWeaponComponent weapon = CBPNK_WarCondition.CurrentWeapon(character);
		if (!weapon || !weapon.GetOwner() || s_RecoilOn.Get(soldierId) == weapon.GetOwner())
			return;

		s_RecoilOn.Set(soldierId, weapon.GetOwner());
		SCR_WeaponStatsManagerComponent stats = SCR_WeaponStatsManagerComponent.Cast(weapon.GetOwner().FindComponent(SCR_WeaponStatsManagerComponent));
		if (!stats)
			return;

		float kick = 1 - 0.35 * Math.Clamp((s_Aim.Get(soldierId) - 40) / 60.0, 0, 1);
		array<BaseMuzzleComponent> muzzles = {};
		weapon.GetMuzzlesList(muzzles);
		bool eased = true;
		foreach (int index, BaseMuzzleComponent muzzle : muzzles)
		{
			eased = stats.SetRecoilLinearFactors(weapon.GetOwner(), index, Vector(kick, kick, kick)) && eased;
			eased = stats.SetRecoilAngularFactors(weapon.GetOwner(), index, Vector(kick, kick, kick)) && eased;
		}
		// A weapon just handed out is not ready for it yet: tried again next tick.
		if (!eased)
		{
			s_RecoilOn.Remove(soldierId);
			if (!s_RecoilFailed.Contains(soldierId))
			{
				s_RecoilFailed.Set(soldierId, true);
				Print(string.Format("CBPNK war: soldier %1 recoil not taken yet (weapon %2, %3 muzzles, kick %4)", soldierId, weapon.GetOwner(), muzzles.Count(), kick), LogLevel.NORMAL);
			}
		}
		else if (kick < 1)
			Print(string.Format("CBPNK war: soldier %1 recoil x%2", soldierId, kick), LogLevel.NORMAL);
	}

	// Endurance: he gets his breath back on the move (stamina added each tick he is short of it).
	protected static void KeepBreath(int soldierId, IEntity body)
	{
		ChimeraCharacter character = ChimeraCharacter.Cast(body);
		if (!character)
			return;
		float breath = 0.04 * Math.Clamp((s_Endurance.Get(soldierId) - 40) / 60.0, 0, 1);
		CharacterControllerComponent controller = character.GetCharacterController();
		if (breath > 0 && controller && controller.GetStamina() < 0.98 && controller.GetStaminaComponent())
			controller.GetStaminaComponent().AddStamina(breath);
	}
}
