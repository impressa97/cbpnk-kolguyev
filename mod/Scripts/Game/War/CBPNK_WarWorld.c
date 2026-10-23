// The island's time and weather, for the commanders on the site.
//   WORLD <hour 0-24> <weather state> <rain 0-100> <fog 0-100> <wind m/s> <wind deg> <sunrise> <sunset> <moon 0-100>
// From the world's TimeAndWeatherManagerEntity (BaseWeatherManagerEntity getters).

class CBPNK_WarWorld
{
	static void WriteState(FileHandle handle)
	{
		ChimeraWorld world = ChimeraWorld.CastFrom(GetGame().GetWorld());
		if (!world)
			return;

		TimeAndWeatherManagerEntity weather = world.GetTimeAndWeatherManager();
		if (!weather)
			return;

		float hour = weather.GetTimeOfTheDay();
		string state = "clear";
		WeatherState current = weather.GetCurrentWeatherState();
		if (current)
			state = current.GetStateName();
		state.Replace("\t", " ");

		float sunrise = 0;
		float sunset = 0;
		weather.GetSunriseHour(sunrise);
		weather.GetSunsetHour(sunset);

		int rain = Math.ClampInt(Math.Round(weather.GetRainIntensity() * 100), 0, 100);
		int fog = Math.ClampInt(Math.Round(weather.GetFogAmount() * 100), 0, 100);
		int moon = Math.ClampInt(Math.Round(weather.GetMoonPhase(hour) * 100), 0, 100);

		float wind = weather.GetWindSpeed();
		int windFrom = Math.Round(weather.GetWindDirection());
		handle.WriteLine(string.Format("WORLD\t%1\t%2\t%3\t%4\t%5\t%6\t%7\t%8\t%9", hour.ToString(-1, 2), state, rain, fog, wind.ToString(-1, 1), windFrom, sunrise.ToString(-1, 2), sunset.ToString(-1, 2), moon));
	}
}
