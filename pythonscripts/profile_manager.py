"""
Profile Manager for DSO Visibility Reports
Handles creating, reading, updating, and deleting location profiles
"""
import json
import os
from pathlib import Path
from geopy.geocoders import Nominatim
from timezonefinder import TimezoneFinder
import re

# Profile storage directory
PROFILE_DIR = Path(__file__).parent / 'profiles'
PROFILE_DIR.mkdir(exist_ok=True)

# Default profile
DEFAULT_PROFILE = {
    'name': 'default',
    'location': 'Star, Idaho',
    'latitude': 43.69,
    'longitude': -116.49,
    'timezone': 'America/Boise',
    'min_altitude': 18.0,
    'az_min': 10.0,
    'az_max': 165.0
}

def geocode_location(location_name):
    """
    Geocode a location name to get latitude, longitude, and timezone.
    
    Args:
        location_name: String like "Star, Idaho" or "New York, NY"
    
    Returns:
        dict with 'latitude', 'longitude', 'timezone', 'display_name'
        or None if geocoding fails
    """
    try:
        # Use Nominatim (OpenStreetMap) - free, no API key required
        geolocator = Nominatim(user_agent="dso_visibility_app")
        location = geolocator.geocode(location_name, timeout=10)
        
        if location is None:
            return None
        
        # Get timezone from coordinates
        tf = TimezoneFinder()
        timezone = tf.timezone_at(lat=location.latitude, lng=location.longitude)
        
        return {
            'latitude': round(location.latitude, 4),
            'longitude': round(location.longitude, 4),
            'timezone': timezone,
            'display_name': location.address
        }
    except Exception as e:
        print(f"Geocoding error: {e}")
        return None


def list_profiles():
    """Get list of all profile names."""
    profiles = []
    for file in PROFILE_DIR.glob('*.json'):
        profiles.append(file.stem)
    
    # Ensure default profile exists
    if 'default' not in profiles:
        save_profile('default', DEFAULT_PROFILE)
        profiles.append('default')
    
    return sorted(profiles)


def load_profile(profile_name='default'):
    """
    Load a profile by name.
    
    Args:
        profile_name: Name of profile to load
    
    Returns:
        dict with profile settings or None if not found
    """
    profile_file = PROFILE_DIR / f'{profile_name}.json'
    
    if not profile_file.exists():
        # Return default profile if requested profile doesn't exist
        if profile_name != 'default':
            return load_profile('default')
        # Create default profile if it doesn't exist
        save_profile('default', DEFAULT_PROFILE)
        return DEFAULT_PROFILE
    
    try:
        with open(profile_file, 'r') as f:
            return json.load(f)
    except Exception as e:
        print(f"Error loading profile: {e}")
        return None

def is_valid_profile_name(profile_name):
    """
    Validate profile name contains only lowercase alphanumeric and underscores.

    Args:
        profile_name: Profile name to validate

    Returns:
        bool: True if valid, False otherwise
    """
    if not profile_name:
        return False
    return bool(re.match(r'^[a-z0-9_]+$', profile_name))

def save_profile(profile_name, profile_data):
    """
    Save a profile.

    Args:
        profile_name: Name for the profile
        profile_data: dict with profile settings

    Returns:
        bool: True if successful, False otherwise
    """
    if not is_valid_profile_name(profile_name):
        print(f"Invalid profile name: {profile_name}. Use only lowercase letters, numbers, and underscores.")
        return False

    try:
        # Ensure name matches
        profile_data['name'] = profile_name

        profile_file = PROFILE_DIR / f'{profile_name}.json'
        with open(profile_file, 'w') as f:
            json.dump(profile_data, f, indent=2)
        return True
    except Exception as e:
        print(f"Error saving profile: {e}")
        return False



def delete_profile(profile_name):
    """
    Delete a profile.
    
    Args:
        profile_name: Name of profile to delete
    
    Returns:
        bool: True if successful, False otherwise
    """
    # Don't allow deleting default profile
    if profile_name == 'default':
        return False
    
    try:
        profile_file = PROFILE_DIR / f'{profile_name}.json'
        if profile_file.exists():
            profile_file.unlink()
            return True
        return False
    except Exception as e:
        print(f"Error deleting profile: {e}")
        return False


def create_profile_from_location(profile_name, location_name, min_altitude=18.0,
                                  az_min=10.0, az_max=165.0):
    """
    Create a new profile by geocoding a location name.

    Args:
        profile_name: Name for the new profile
        location_name: Location string to geocode
        min_altitude: Minimum altitude in degrees
        az_min: Minimum azimuth in degrees
        az_max: Maximum azimuth in degrees

    Returns:
        dict: Profile data if successful, None if geocoding fails
    """
    if not is_valid_profile_name(profile_name):
        print(f"Invalid profile name: {profile_name}. Use only lowercase letters, numbers, and underscores.")
        return None

    geo_data = geocode_location(location_name)

    if geo_data is None:
        return None

    profile = {
        'name': profile_name,
        'location': location_name,
        'latitude': geo_data['latitude'],
        'longitude': geo_data['longitude'],
        'timezone': geo_data['timezone'],
        'min_altitude': min_altitude,
        'az_min': az_min,
        'az_max': az_max,
        'geocoded_name': geo_data['display_name']
    }

    if save_profile(profile_name, profile):
        return profile

    return None


if __name__ == '__main__':
    # Test geocoding
    print("Testing geocoding...")
    result = geocode_location("Star, Idaho")
    if result:
        print(f"Location: {result['display_name']}")
        print(f"Coordinates: {result['latitude']}, {result['longitude']}")
        print(f"Timezone: {result['timezone']}")
    
    # Create default profile
    print("\nCreating default profile...")
    save_profile('default', DEFAULT_PROFILE)
    
    # List profiles
    print("\nProfiles:", list_profiles())
