"""
The script performs the following operations:
1. Reads the existing JSON file
2. For each DSO entry, checks if CommonName exists
3. If CommonName is missing or empty, moves the first OtherNames entry to CommonName
4. Removes the moved entry from OtherNames array
5. Falls back to using the key as CommonName if no other names exist
6. Writes the updated structure back to the file
"""
import json

WATCHLIST_FILE = '../public/dso_watchlist_info.json'
# Read the JSON file
with open(WATCHLIST_FILE, 'r', encoding='utf-8') as f:
    data = json.load(f)

# Create a new dictionary with updated structure
new_data = {}

for key, value in data.items():
    # Check if CommonName already exists
    if 'CommonName' not in value or not value['CommonName']:
        # If OtherNames has items, move first one to CommonName
        if value.get('OtherNames') and len(value['OtherNames']) > 0:
            value['CommonName'] = value['OtherNames'][0]
            value['OtherNames'] = value['OtherNames'][1:]
        else:
            # Use the key itself as CommonName if no other names
            value['CommonName'] = key
            if 'OtherNames' not in value:
                value['OtherNames'] = []
    
    new_data[key] = value

# Write the updated JSON file
with open(WATCHLIST_FILE, 'w', encoding='utf-8') as f:
    json.dump(new_data, f, indent=2, ensure_ascii=False)

print("JSON file updated successfully!")
print(f"Processed {len(new_data)} entries")
