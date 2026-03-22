"""Test that replacement generation is working correctly."""

import sys
sys.stdout.reconfigure(encoding='utf-8')

ALEPH = chr(0x02BE)  # ʾ
AYIN = chr(0x02BF)   # ʿ
LEFT_SINGLE = chr(0x2018)  # '

APOSTROPHE_VARIANTS = [
    "'",      # Regular apostrophe (U+0027)
    "'",      # Right single quote (U+2019) - curly apostrophe
    "'",      # Left single quote (U+2018) - left curly quote
    "`",      # Backtick (U+0060)
    "´",      # Acute accent (U+00B4)
    "ʼ",      # Modifier letter apostrophe (U+02BC)
]

BASE_REPLACEMENTS = [
    ("'Adam", "ʾAdam"),
    ("'Abraham", "ʾAbraham"),
]

def generate_all_variants():
    """Generate replacement pairs for each apostrophe variant."""
    all_replacements = []

    for old_pattern, new_pattern in BASE_REPLACEMENTS:
        # Create version for each apostrophe variant
        for apos in APOSTROPHE_VARIANTS:
            variant_old = old_pattern.replace("'", apos)
            if variant_old != new_pattern:  # Skip if already correct
                all_replacements.append((variant_old, new_pattern))

    return all_replacements

ALL_REPLACEMENTS = generate_all_variants()

print(f"Generated {len(ALL_REPLACEMENTS)} replacement patterns:\n")

# Show patterns for Abraham
print("Abraham patterns:")
for old, new in ALL_REPLACEMENTS:
    if 'Abraham' in old or 'Abraham' in new:
        print(f"  {repr(old)} → {repr(new)}")

print("\nAdam patterns:")
for old, new in ALL_REPLACEMENTS:
    if 'Adam' in old or 'Adam' in new:
        print(f"  {repr(old)} → {repr(new)}")

# Test specific pattern
test_text = f"descendant of {LEFT_SINGLE}Abraham, Yitschaq"
print(f"\n\nTest text: {repr(test_text)}")

# Try to match
for old_pattern, new_pattern in ALL_REPLACEMENTS:
    if old_pattern in test_text:
        print(f"  MATCH: {repr(old_pattern)} found!")
        new_test = test_text.replace(old_pattern, new_pattern)
        print(f"  Result: {repr(new_test)}")
        break
else:
    print("  NO MATCH FOUND!")
