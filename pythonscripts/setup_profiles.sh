#!/bin/bash

echo "========================================"
echo "DSO Visibility - Profile System Setup"
echo "========================================"
echo ""

cd "$(dirname "$0")"

echo "Activating virtual environment..."
source venv/bin/activate

echo ""
echo "Installing/updating dependencies..."
pip install -r requirements.txt

echo ""
echo "Initializing default profile..."
python profile_manager.py

echo ""
echo "Testing geocoding..."
python profile_cli.py geocode "Star, Idaho"

echo ""
echo "========================================"
echo "Setup complete!"
echo "========================================"
echo ""
echo "Next steps:"
echo "1. Visit http://localhost/profiles.php to manage profiles"
echo "2. Create profiles for your observing locations"
echo "3. Use profiles: http://localhost/vis?profile=your-profile-name"
echo ""
