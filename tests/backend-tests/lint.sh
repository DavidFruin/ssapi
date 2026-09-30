#!/bin/bash
# lint.sh - Code Quality Checks
DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$DIR/../.."

echo "=== Code Quality Checks ===
"

# PHP Syntax Check
echo "Checking PHP syntax..."
php -l "$ROOT/api.php" > /dev/null 2>&1
if [ $? -eq 0 ]; then
  echo "✓ PHP syntax OK"
else
  echo "✗ PHP syntax errors found"
fi

# JS Syntax Check
echo "Checking JS syntax..."
JS_OK=true
for f in $(find "$ROOT/js" -name "*.js" -type f); do
  node --check "$f" > /dev/null 2>&1
  if [ $? -ne 0 ]; then
    echo "✗ JS syntax error: $f"
    JS_OK=false
  fi
done
if [ "$JS_OK" = true ]; then
  echo "✓ JS syntax OK"
fi

# Count lines of code
echo "
Code Statistics:"
echo "  PHP: $(wc -l < "$ROOT/api.php") lines"
JS_LINES=$(find "$ROOT/js" -name "*.js" -type f -exec cat {} + | wc -l)
echo "  JS: $JS_LINES lines"

# Check max indentation
echo "
Indentation:"
echo "  PHP max: $(perl -lane 'print length($1) if /^(\s+)/' "$ROOT/api.php" | sort -rn | head -1) spaces"

# Count functions
echo "
Functions:"
echo "  PHP: $(grep -c '^function ' "$ROOT/api.php") functions"

echo "
=== Checks Complete ==="
