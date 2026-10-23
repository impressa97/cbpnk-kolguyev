// How a soldier is doing right now, read from vanilla components each tick.
//   SOLDIER <id> ok <health 0-100> <bleeding 0|1> <down 0|1> <stamina 0-100> <safe|vigilant|alerted|threatened> <mags|-1> <in_vehicle 0|1> <x> <z> <driver|gunner|passenger|-> <stand|crouch|prone> <action> <heading 0-359|-1> <target soldier id|0>
//   action is the class of the AI action he is on (AIBaseUtilityComponent.GetCurrentAction),
//   SCR_AI and Behavior/Activity trimmed: Attack, MoveInFormation, Heal, GetInVehicle, Idle…
// Health is SCR_CharacterDamageManagerComponent.GetHealthScaled, down is the controller's
// unconscious state, threat is the agent's SCR_AIThreatSystem state, mags counts spare
// magazines for the weapon in hand.

class CBPNK_WarCondition
{
	static string Line(int soldierId, IEntity body)
	{
		int health = 100;
		int bleeding = 0;
		SCR_CharacterDamageManagerComponent damage = SCR_CharacterDamageManagerComponent.Cast(body.FindComponent(SCR_CharacterDamageManagerComponent));
		if (damage)
		{
			health = Math.Round(damage.GetHealthScaled() * 100);
			if (damage.IsBleeding())
				bleeding = 1;
		}

		int down = 0;
		int stamina = 100;
		int inVehicle = 0;
		int mags = -1;
		ChimeraCharacter character = ChimeraCharacter.Cast(body);
		if (character)
		{
			CharacterControllerComponent controller = character.GetCharacterController();
			if (controller)
			{
				if (controller.IsUnconscious())
					down = 1;
				stamina = Math.Round(controller.GetStamina() * 100);
			}

			if (character.IsInVehicle())
				inVehicle = 1;

			mags = SpareMagazines(character);
		}

		string line = string.Format("SOLDIER\t%1\tok\t%2\t%3\t%4\t%5\t%6\t%7\t%8", soldierId, Math.ClampInt(health, 0, 100), bleeding, down, Math.ClampInt(stamina, 0, 100), Threat(body), mags, inVehicle);

		// Where he stands and, aboard, which seat (string.Format takes nine arguments at most).
		vector at = body.GetOrigin();
		float east = at[0];
		float north = at[2];
		return line + "\t" + east.ToString(-1, 2) + "\t" + north.ToString(-1, 2) + "\t" + Seat(body) + "\t" + Stance(body) + "\t" + Action(body) + "\t" + Heading(body) + "\t" + Target(body);
	}

	// Where he looks, as a compass bearing (0 north, 90 east): the game's head aiming for a man,
	// the hull for a vehicle; -1 when there is nothing to read.
	static int Heading(IEntity body)
	{
		if (!body)
			return -1;
		vector dir;
		ChimeraCharacter character = ChimeraCharacter.Cast(body);
		if (character && character.GetHeadAimingComponent())
		{
			// The head aiming direction comes mirrored east to west (checked in game: men walking
			// east reported ~260); turn it back.
			dir = character.GetHeadAimingComponent().GetAimingDirectionWorld();
			dir[0] = -dir[0];
		}
		else
		{
			vector mat[4];
			body.GetWorldTransform(mat);
			dir = mat[2];
		}
		if (dir[0] == 0 && dir[2] == 0)
			return -1;
		int bearing = Math.Round(Math.Atan2(dir[0], dir[2]) * Math.RAD2DEG);
		return (bearing + 360) % 360;
	}

	// The enemy he is fighting: the game's current target (SCR_AICombatComponent), as one of the
	// war's soldiers; 0 for none, or for one that is no soldier of ours (a vehicle, a stranger).
	static int Target(IEntity body)
	{
		SCR_AICombatComponent combat = SCR_AICombatComponent.Cast(body.FindComponent(SCR_AICombatComponent));
		if (!combat)
			return 0;
		BaseTarget target = combat.GetCurrentTarget();
		if (!target)
			return 0;
		IEntity enemy = target.GetTargetEntity();
		if (!enemy)
			return 0;
		return CBPNK_WarBridge.SoldierOf(enemy);
	}

	static string Stance(IEntity body)
	{
		ChimeraCharacter character = ChimeraCharacter.Cast(body);
		if (!character || !character.GetCharacterController())
			return "stand";

		ECharacterStance stance = character.GetCharacterController().GetStance();
		if (stance == ECharacterStance.PRONE)
			return "prone";
		if (stance == ECharacterStance.CROUCH)
			return "crouch";
		return "stand";
	}

	// What his AI is doing right now, by the action's class name.
	static string Action(IEntity body)
	{
		AIControlComponent control = AIControlComponent.Cast(body.FindComponent(AIControlComponent));
		if (!control)
			return "-";
		AIAgent agent = control.GetControlAIAgent();
		if (!agent)
			return "-";
		AIBaseUtilityComponent utility = AIBaseUtilityComponent.Cast(agent.FindComponent(AIBaseUtilityComponent));
		if (!utility)
			return "-";
		AIActionBase action = utility.GetCurrentAction();
		if (!action)
			return "Idle";

		string name = action.Type().ToString();
		name.Replace("SCR_AI", "");
		name.Replace("Behavior", "");
		name.Replace("Activity", "");
		if (name.IsEmpty())
			return "-";
		return name;
	}

	// The compartment he sits in, as the game types it: driver (PILOT), gunner (TURRET), passenger (CARGO).
	static string Seat(IEntity body)
	{
		CompartmentAccessComponent access = CompartmentAccessComponent.Cast(body.FindComponent(CompartmentAccessComponent));
		if (!access || !access.IsInCompartment())
			return "-";

		BaseCompartmentSlot slot = access.GetCompartment();
		if (!slot)
			return "-";

		ECompartmentType type = slot.GetType();
		if (type == ECompartmentType.PILOT)
			return "driver";
		if (type == ECompartmentType.TURRET)
			return "gunner";
		return "passenger";
	}

	static string Threat(IEntity body)
	{
		SCR_AIInfoComponent info = Info(body);
		if (!info)
			return "safe";

		EAIThreatState state = info.GetThreatState();
		if (state == EAIThreatState.THREATENED)
			return "threatened";
		if (state == EAIThreatState.ALERTED)
			return "alerted";
		if (state == EAIThreatState.VIGILANT)
			return "vigilant";
		return "safe";
	}

	static bool IsCalm(IEntity body)
	{
		string threat = Threat(body);
		return threat == "safe" || threat == "vigilant";
	}

	static SCR_AIInfoComponent Info(IEntity body)
	{
		AIControlComponent control = AIControlComponent.Cast(body.FindComponent(AIControlComponent));
		if (!control)
			return null;

		AIAgent agent = control.GetControlAIAgent();
		if (!agent)
			return null;

		return SCR_AIInfoComponent.Cast(agent.FindComponent(SCR_AIInfoComponent));
	}

	static int SpareMagazines(ChimeraCharacter character)
	{
		BaseWeaponComponent weapon = CurrentWeapon(character);
		if (!weapon)
			return -1;

		SCR_InventoryStorageManagerComponent inventory = SCR_InventoryStorageManagerComponent.Cast(character.FindComponent(SCR_InventoryStorageManagerComponent));
		if (!inventory)
			return -1;

		return inventory.GetMagazineCountByWeapon(weapon);
	}

	static BaseWeaponComponent CurrentWeapon(ChimeraCharacter character)
	{
		BaseWeaponManagerComponent weapons = character.GetWeaponManager();
		if (!weapons)
			return null;

		return weapons.GetCurrentWeapon();
	}
}
