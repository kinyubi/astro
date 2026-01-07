#!/usr/bin/env python3
"""
Command-line interface for profile management
Used by profiles.php to manage location profiles
"""
import sys
import json
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
            'error': 'Failed to geocode location or save profile'
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
        print(json.dumps({'success': False, 'error': 'Location not found'}))
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
    else:
        parser.print_help()
        sys.exit(1)
