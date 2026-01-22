#!/usr/bin/env python3
"""
delete_orphans.py

Finds and deletes orphan image files that don't have corresponding versions
in all 6 image directories (fav, full, wall, annotated_fav, annotated_full, annotated_wall).

A complete set requires all 6 versions of an image to exist.
Orphans are files that exist in some directories but not all 6.
"""

import os
import sys
from pathlib import Path
from collections import defaultdict

# Configuration
BASE_PATH = Path(r"C:\laragon7\www\astro\public\images")

# Directory names and their corresponding filename suffixes
DIRECTORIES = {
    "fav": "_fav",
    "full": "_full",
    "wall": "_wall",
    "annotated_fav": "_fav_annotated",
    "annotated_full": "_full_annotated",
    "annotated_wall": "_wall_annotated",
}

VALID_EXTENSIONS = {".jpg", ".jpeg", ".png", ".gif", ".webp"}


def extract_base_name(filename: str, suffix: str) -> str | None:
    """
    Extract the base name from a filename by removing the suffix and extension.
    
    Example: "M31_20250113_fav.jpg" with suffix "_fav" -> "M31_20250113"
    """
    path = Path(filename)
    
    # Check if valid image extension
    if path.suffix.lower() not in VALID_EXTENSIONS:
        return None
    
    # Remove extension first
    name_without_ext = path.stem
    
    # Check if the name ends with the expected suffix
    if not name_without_ext.endswith(suffix):
        return None
    
    # Remove the suffix to get base name
    base_name = name_without_ext[: -len(suffix)]
    
    return base_name if base_name else None


def scan_directory(dir_path: Path, suffix: str) -> dict[str, str]:
    """
    Scan a directory and return a dict mapping base_name -> full_filepath.
    """
    files = {}
    
    if not dir_path.exists():
        print(f"  Warning: Directory does not exist: {dir_path}")
        return files
    
    for entry in dir_path.iterdir():
        if entry.is_file():
            base_name = extract_base_name(entry.name, suffix)
            if base_name:
                files[base_name] = str(entry)
    
    return files


def main():
    print("=" * 70)
    print("Image Orphan Finder")
    print("=" * 70)
    print(f"\nBase path: {BASE_PATH}\n")
    
    # Step 1: Scan all directories and collect base names
    print("Scanning directories...")
    dir_contents = {}  # dir_name -> {base_name: filepath}
    all_base_names = defaultdict(set)  # base_name -> set of dir_names where it exists
    
    for dir_name, suffix in DIRECTORIES.items():
        dir_path = BASE_PATH / dir_name
        print(f"  Scanning {dir_name}/...")
        files = scan_directory(dir_path, suffix)
        dir_contents[dir_name] = files
        print(f"    Found {len(files)} valid image files")
        
        for base_name in files:
            all_base_names[base_name].add(dir_name)
    
    # Step 2: Find base names that exist in ALL 6 directories (master list)
    all_dir_names = set(DIRECTORIES.keys())
    master_list = {
        base_name
        for base_name, dirs in all_base_names.items()
        if dirs == all_dir_names
    }
    
    print(f"\n{'=' * 70}")
    print(f"MASTER LIST: {len(master_list)} objects have complete sets (all 6 versions)")
    print("=" * 70)
    
    if master_list:
        for name in sorted(master_list):
            print(f"  ✓ {name}")
    else:
        print("  (none)")
    
    # Step 3: Find orphan files (files whose base_name is NOT in master list)
    orphans = []  # list of (base_name, dir_name, filepath)
    
    for dir_name, files in dir_contents.items():
        for base_name, filepath in files.items():
            if base_name not in master_list:
                orphans.append((base_name, dir_name, filepath))
    
    # Sort orphans by base_name, then by directory
    orphans.sort(key=lambda x: (x[0], x[1]))
    
    print(f"\n{'=' * 70}")
    print(f"ORPHAN FILES: {len(orphans)} files found")
    print("=" * 70)
    
    if not orphans:
        print("  No orphan files found. All directories are in sync!")
        return
    
    # Group orphans by base_name for clearer reporting
    orphans_by_base = defaultdict(list)
    for base_name, dir_name, filepath in orphans:
        orphans_by_base[base_name].append((dir_name, filepath))
    
    for base_name in sorted(orphans_by_base.keys()):
        entries = orphans_by_base[base_name]
        # Find which directories are missing
        present_dirs = {e[0] for e in entries}
        missing_dirs = all_dir_names - present_dirs
        
        print(f"\n  {base_name}")
        print(f"    Present in: {', '.join(sorted(present_dirs))}")
        print(f"    Missing in: {', '.join(sorted(missing_dirs))}")
        print("    Files to delete:")
        for dir_name, filepath in sorted(entries):
            print(f"      - {filepath}")
    
    # Step 4: Confirm deletion
    print(f"\n{'=' * 70}")
    print(f"SUMMARY: {len(orphans)} orphan files will be deleted")
    print("=" * 70)
    
    response = input("\nDo you want to delete all orphan files? (yes/no): ").strip().lower()
    
    if response != "yes":
        print("\nDeletion cancelled. No files were deleted.")
        return
    
    # Step 5: Delete orphan files
    print("\nDeleting orphan files...")
    deleted_count = 0
    error_count = 0
    
    for base_name, dir_name, filepath in orphans:
        try:
            os.remove(filepath)
            print(f"  Deleted: {filepath}")
            deleted_count += 1
        except OSError as e:
            print(f"  ERROR deleting {filepath}: {e}")
            error_count += 1
    
    print(f"\n{'=' * 70}")
    print(f"COMPLETE: {deleted_count} files deleted, {error_count} errors")
    print("=" * 70)


if __name__ == "__main__":
    main()
