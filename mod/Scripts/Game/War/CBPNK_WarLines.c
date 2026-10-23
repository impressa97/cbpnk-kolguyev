// orders.tsv lines the bridge keeps until the whole file is read, as typed records.

// SOLDIER <id> <squad_id> <role> <aim> <grit> <fieldcraft> <service> [<stance> <pace> [<endurance>]]
class CBPNK_WarSoldierWish
{
	int id;
	int squadId;
	string role;
	int aim;
	int grit;
	int fieldcraft;
	int endurance = 40;
	string stance = "auto";
	string pace = "auto";

	static CBPNK_WarSoldierWish Parse(array<string> bits)
	{
		if (bits.Count() < 7)
			return null;

		CBPNK_WarSoldierWish wish = new CBPNK_WarSoldierWish();
		wish.id = bits[1].ToInt();
		wish.squadId = bits[2].ToInt();
		wish.role = bits[3];
		wish.aim = bits[4].ToInt();
		wish.grit = bits[5].ToInt();
		wish.fieldcraft = bits[6].ToInt();
		if (bits.Count() >= 10)
		{
			wish.stance = bits[8];
			wish.pace = bits[9];
		}
		if (bits.Count() >= 11)
			wish.endurance = bits[10].ToInt();
		return wish;
	}
}

// ORDER <id> <squad_id> <side> <x> <z> [<kind> [<target> [<x2> <z2>]]], and its LEG lines.
class CBPNK_WarOrderLine
{
	int id;
	int squadId;
	float x;
	float z;
	string kind = "move";
	// The soldier to treat, the vehicle to board, the squad to follow, a scout's seconds: 0 if none.
	int targetId;
	// A patrol's other end.
	vector second = vector.Zero;
	// A move's further points, in order.
	ref array<vector> route;

	static CBPNK_WarOrderLine Parse(array<string> bits)
	{
		if (bits.Count() < 6)
			return null;

		CBPNK_WarOrderLine order = new CBPNK_WarOrderLine();
		order.id = bits[1].ToInt();
		order.squadId = bits[2].ToInt();
		order.x = bits[4].ToFloat();
		order.z = bits[5].ToFloat();
		if (bits.Count() >= 7)
			order.kind = bits[6];
		if (bits.Count() >= 8)
			order.targetId = bits[7].ToInt();
		if (bits.Count() >= 10)
			order.second = Vector(bits[8].ToFloat(), 0, bits[9].ToFloat());
		return order;
	}
}
