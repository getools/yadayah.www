"""Check actual character codes in patterns."""

import sys
sys.stdout.reconfigure(encoding='utf-8')

ALEPH = chr(0x02BE)  # ʾ
LEFT_SINGLE = chr(0x2018)  # '
REGULAR = "'"  # Whatever Python interprets this as

APOSTROPHE_VARIANTS = [
    chr(0x0027),  # Regular apostrophe '
    chr(0x2019),  # Right single quote ' (curly apostrophe)
    chr(0x2018),  # Left single quote ' (left curly quote)
    chr(0x0060),  # Backtick `
    chr(0x00B4),  # Acute accent ´
    chr(0x02BC),  # Modifier letter apostrophe ʼ
]

print("Apostrophe variants in list:")
for i, apos in enumerate(APOSTROPHE_VARIANTS):
    print(f"  [{i}] {repr(apos)} = U+{ord(apos):04X}")

print(f"\nLEFT_SINGLE = {repr(LEFT_SINGLE)} = U+{ord(LEFT_SINGLE):04X}")
print(f"REGULAR = {repr(REGULAR)} = U+{ord(REGULAR):04X}")

# Check if LEFT_SINGLE is in the variants list
if LEFT_SINGLE in APOSTROPHE_VARIANTS:
    idx = APOSTROPHE_VARIANTS.index(LEFT_SINGLE)
    print(f"\n✓ LEFT_SINGLE found at index {idx}")
else:
    print(f"\n✗ LEFT_SINGLE NOT in variants list!")

# Now test the pattern
BASE_REPLACEMENTS = [
    ("'Abraham", "ʾAbraham"),
]

print("\n\nBASE_REPLACEMENTS:")
for old, new in BASE_REPLACEMENTS:
    print(f"  {repr(old)} → {repr(new)}")
    print(f"    First char of old: U+{ord(old[0]):04X}")

def generate_all_variants():
    all_replacements = []
    for old_pattern, new_pattern in BASE_REPLACEMENTS:
        for apos in APOSTROPHE_VARIANTS:
            variant_old = old_pattern.replace("'", apos)
            if variant_old != new_pattern:
                all_replacements.append((variant_old, new_pattern))
    return all_replacements

ALL_REPLACEMENTS = generate_all_variants()

print(f"\n\nGenerated {len(ALL_REPLACEMENTS)} patterns:")
for old, new in ALL_REPLACEMENTS:
    first_char = old[0]
    print(f"  {repr(old)} (first=U+{ord(first_char):04X}) → {repr(new)}")

# Test matching
test_text = f"descendant of {LEFT_SINGLE}Abraham, Yitschaq"
print(f"\n\nTest text: {repr(test_text)}")
print(f"  Character before Abraham: U+{ord(test_text[14]):04X}")

for old_pattern, new_pattern in ALL_REPLACEMENTS:
    if old_pattern in test_text:
        print(f"\n✓ MATCH: {repr(old_pattern)}")
        break
else:
    print(f"\n✗ NO MATCH")

    # Try manual check
    print("\nManual checks:")
    for old_pattern, new_pattern in ALL_REPLACEMENTS:
        print(f"  Testing {repr(old_pattern)}:")
        print(f"    in test_text: {old_pattern in test_text}")
