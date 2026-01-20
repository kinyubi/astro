#!/usr/bin/env python3
"""
Command-line interface for profile management
Used by profiles.php to manage location profiles
"""
import sys
import json
import re
import argparse
from profile_manager import (
    list_profiles, 
    load_profile, 
    save_profile, 
    delete_profile,
    create_profile_from_location,
    geocode_location
)


def cmd_list():
    """List all profiles as JSON array."""
    profiles = list_profiles()
    profile_data = []
    
    for name in profiles:
        profile = load_profile(name)
        if profile:
            profile_data.append(profile)
    
    print(json.dumps(profile_data, indent=2))


def cmd_get(profile_name):
    """Get a single profile as JSON."""
    profile = load_profile(profile_name)
    if profile:
        print(json.dumps(profile, indent=2))
    else:
        print(json.dumps({'error': f'Profile {profile_name} not found'}))
        sys.exit(1)


def cmd_create(profile_name, location, min_altitude, az_min, az_max):
    """Create a new profile."""
    # Validate profile name first
    if not profile_name:
        print(json.dumps({
            'success': False,
            'error': 'Profile name is required'
        }))
        sys.exit(1)
    
    if not re.match(r'^[a-z0-9_]+$', profile_name):
        print(json.dumps({
            'success': False,
            'error': f"Invalid profile name '{profile_name}'. Use only lowercase letters, numbers, and underscores (no spaces, hyphens, or uppercase)."
        }))
        sys.exit(1)
    
    # Check if profile already exists
    existing = load_profile(profile_name)
    if existing and existing.get('name') == profile_name:
        print(json.dumps({
            'success': False,
            'error': f"Profile '{profile_name}' already exists. Use edit to modify it."
        }))
        sys.exit(1)
    
    profile = create_profile_from_location(
        profile_name, 
        location, 
        min_altitude, 
        az_min, 
        az_max
    )
    
    if profile:
        print(json.dumps({'success': True, 'profile': profile}))
    else:
        print(json.dumps({
            'success': False, 
            'error': f"Failed to geocode location '{location}'. Try a more specific location like 'City, State' or 'City, Country'."
        }))
        sys.exit(1)


def cmd_delete(profile_name):
    """Delete a profile."""
    if delete_profile(profile_name):
        print(json.dumps({'success': True, 'message': f'Profile {profile_name} deleted'}))
    else:
        print(json.dumps({'success': False, 'error': 'Failed to delete profile'}))
        sys.exit(1)


def cmd_geocode(location):
    """Test geocoding a location."""
    result = geocode_location(location)
    if result:
        print(json.dumps({'success': True, **result}))
    else:
        print(json.dumps({'success': False, 'error': f"Could not find location '{location}'. Try a more specific format like 'City, State' or 'City, Country'."}))
        sys.exit(1)


def cmd_update(profile_name, location=None, min_altitude=None, az_min=None, az_max=None):
    """Update an existing profile."""
    # Load existing profile
    profile = load_profile(profile_name)
    if not profile or profile.get('name') != profile_name:
        print(json.dumps({
            'success': False,
            'error': f"Profile '{profile_name}' not found"
        }))
        sys.exit(1)
    
    # If location changed, re-geocode
    if location and location != profile.get('location'):
        geo_data = geocode_location(location)
        if geo_data:
            profile['location'] = location
            profile['latitude'] = geo_data['latitude']
            profile['longitude'] = geo_data['longitude']
            profile['timezone'] = geo_data['timezone']
            profile['geocoded_name'] = geo_data['display_name']
        else:
            print(json.dumps({
                'success': False,
                'error': f"Could not geocode new location '{location}'. Profile not updated."
            }))
            sys.exit(1)
    
    # Update other fields if provided
    if min_altitude is not None:
        profile['min_altitude'] = min_altitude
    if az_min is not None:
        profile['az_min'] = az_min
    if az_max is not None:
        profile['az_max'] = az_max
    
    # Save updated profile
    if save_profile(profile_name, profile):
        print(json.dumps({'success': True, 'profile': profile}))
    else:
        print(json.dumps({
            'success': False,
            'error': 'Failed to save updated profile'
        }))
        sys.exit(1)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Profile management CLI')
    subparsers = parser.add_subparsers(dest='command', help='Command to execute')
    
    # List command
    subparsers.add_parser('list', help='List all profiles')
    
    # Get command
    get_parser = subparsers.add_parser('get', help='Get a profile')
    get_parser.add_argument('profile_name', help='Profile name')
    
    # Create command
    create_parser = subparsers.add_parser('create', help='Create a new profile')
    create_parser.add_argument('profile_name', help='Profile name')
    create_parser.add_argument('location', help='Location to geocode')
    create_parser.add_argument('--min-altitude', type=float, default=18.0, 
                               help='Minimum altitude in degrees')
    create_parser.add_argument('--az-min', type=float, default=10.0,
                               help='Minimum azimuth in degrees')
    create_parser.add_argument('--az-max', type=float, default=165.0,
                               help='Maximum azimuth in degrees')
    
    # Delete command
    delete_parser = subparsers.add_parser('delete', help='Delete a profile')
    delete_parser.add_argument('profile_name', help='Profile name')
    
    # Update command
    update_parser = subparsers.add_parser('update', help='Update an existing profile')
    update_parser.add_argument('profile_name', help='Profile name')
    update_parser.add_argument('--location', help='New location to geocode')
    update_parser.add_argument('--min-altitude', type=float, help='Minimum altitude in degrees')
    update_parser.add_argument('--az-min', type=float, help='Minimum azimuth in degrees')
    update_parser.add_argument('--az-max', type=float, help='Maximum azimuth in degrees')
    
    # Geocode command
    geocode_parser = subparsers.add_parser('geocode', help='Test geocoding a location')
    geocode_parser.add_argument('location', help='Location to geocode')
    
    args = parser.parse_args()
    
    if args.command == 'list':
        cmd_list()
    elif args.command == 'get':
        cmd_get(args.profile_name)
    elif args.command == 'create':
        cmd_create(
            args.profile_name, 
            args.location, 
            args.min_altitude,
            args.az_min, 
            args.az_max
        )
    elif args.command == 'delete':
        cmd_delete(args.profile_name)
    elif args.command == 'geocode':
        cmd_geocode(args.location)
    elif args.command == 'update':
        cmd_update(
            args.profile_name,
            args.location,
            args.min_altitude,
            args.az_min,
            args.az_max
        )
    else:
        parser.print_help()
        sys.exit(1)
