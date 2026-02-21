# 🚀 Quick Start - Profile System

## Install & Setup (2 minutes)

### Windows
```bash
cd C:\laragon7\www\astro\pythonscripts
setup_profiles.bat
```

### Linux/Mac
```bash
cd /path/to/pythonscripts
chmod +x setup_profiles.sh
./setup_profiles.sh
```

That's it! The script installs dependencies, creates the default profile, and tests geocoding.

## Create Your First Profile (1 minute)

1. Visit: **http://localhost/profiles.php**

2. Scroll to "Create New Profile"

3. Enter:
   - **Profile Name:** `backyard`
   - **Location:** `Your City, Your State`
   - Click **"🔍 Test Geocode"** to verify

4. If coordinates look correct, click **"✨ Create Profile"**

5. Done! Your profile is ready to use.

## Use Your Profile (instant)

Click **"🔭 Use Profile"** on any profile card, or visit:

```
http://localhost/vis?profile=backyard
```

The report now uses your backyard's coordinates, timezone, and viewing constraints!

## URL Cheat Sheet

```bash
# Default location, today
/vis

# Your backyard, today  
/vis?profile=backyard

# Your backyard, specific date
/vis?profile=backyard&date=2025-02-15

# Force rebuild with profile
/vis?profile=backyard&rebuild=1

# Manage profiles
/profiles.php
```

## Common Profiles to Create

```
backyard        Your home observing site
dark-site       Best dark sky location
club            Astronomy club meeting site
portable        Mobile setup location
vacation        Travel destination
```

## Pro Tips

💡 **Test location first** - Always click "Test Geocode" before creating a profile to verify the location is found

💡 **Be specific** - Use "Phoenix, AZ" not just "Phoenix" for better geocoding

💡 **Each profile cached separately** - Switch between profiles instantly after first load

💡 **Safe defaults** - Leave altitude/azimuth at defaults unless you have specific horizon obstructions

💡 **Can't delete default** - The default profile is protected and can't be deleted

## Example Workflow

### Scenario: Planning a trip to Arizona

1. Create profile: `arizona-trip`
2. Location: `Flagstaff, Arizona`
3. Use profile: `/vis?profile=arizona-trip&date=2025-03-15`
4. See what DSOs will be visible on your trip!
5. Share URL with travel companions

### Scenario: Multiple backyards

1. Create: `home-backyard` - Your house
2. Create: `parents-house` - Parents' location
3. Create: `cabin` - Vacation cabin
4. Switch between them instantly with profile dropdown

## Troubleshooting

**Can't find location?**
- Add state/country: "Portland, OR" not "Portland"
- Try nearby major city
- Check spelling

**Profile not working?**
- Verify profile name in URL matches exactly
- Check profile exists at `/profiles.php`
- Clear cache with `?rebuild=1`

**Dependencies error?**
- Run setup script again
- Or manually: `pip install geopy timezonefinder`

## Next Steps

✅ Create profiles for your observing locations
✅ Compare visibility between locations  
✅ Plan trips with destination profiles
✅ Share profile URLs with astronomy friends

## Need Help?

📖 **Detailed Guide:** Read `PROFILE_SYSTEM_GUIDE.md`
📋 **Full Documentation:** See `PROFILE_IMPLEMENTATION_SUMMARY.md`
🔧 **Setup Issues:** Check `PROFILE_SYSTEM_GUIDE.md` troubleshooting section

---

**You're all set!** 🎉 No more hardcoded values - just create profiles and explore the night sky from anywhere!
