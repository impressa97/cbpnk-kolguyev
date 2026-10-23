// Server admins for the war server. It runs without a config file (the war mod is a local
// addon, which a config cannot name), so it has no admin password and no admin list. Instead
// $profile:cbpnk-war/admins.txt lists Reforger identity ids, one a line ('#' starts a comment).
// A listed player gets the game's administrator role, and the Game Master editor gives an admin
// its own modes, the free camera among them (SCR_EditorManagerCore.OnPlayerRoleChange). Each
// player's name and identity is printed once when he joins, to copy into the list.

class CBPNK_WarAdmins
{
	static const string FILE = "$profile:cbpnk-war/admins.txt";

	protected static ref map<int, bool> s_Seen = new map<int, bool>();

	// Every bridge tick: anyone listed who is not an admin yet becomes one.
	static void Tend()
	{
		if (!Replication.IsServer())
			return;

		array<string> listed = Listed();
		PlayerManager players = GetGame().GetPlayerManager();
		array<int> ids = {};
		players.GetPlayers(ids);
		foreach (int playerId : ids)
		{
			string identity = GetGame().GetBackendApi().GetPlayerIdentityId(playerId);
			if (!s_Seen.Contains(playerId))
			{
				s_Seen.Set(playerId, true);
				Print(string.Format("CBPNK war: player %1 '%2' identity %3", playerId, players.GetPlayerName(playerId), identity), LogLevel.NORMAL);
			}
			if (identity != "" && listed.Contains(identity) && !players.HasPlayerRole(playerId, EPlayerRole.ADMINISTRATOR))
			{
				players.GivePlayerRole(playerId, EPlayerRole.ADMINISTRATOR);
				Print(string.Format("CBPNK war: player %1 '%2' made administrator", playerId, players.GetPlayerName(playerId)), LogLevel.NORMAL);
			}
		}
	}

	protected static array<string> Listed()
	{
		array<string> listed = {};
		if (!FileIO.FileExists(FILE))
			return listed;

		FileHandle handle = FileIO.OpenFile(FILE, FileMode.READ);
		if (!handle)
			return listed;

		string line;
		int guard = 0;
		while (handle.ReadLine(line) >= 0 && guard < 256)
		{
			guard++;
			line.Trim();
			if (line.IsEmpty() || line.StartsWith("#"))
				continue;
			listed.Insert(line);
		}
		handle.Close();
		return listed;
	}
}
