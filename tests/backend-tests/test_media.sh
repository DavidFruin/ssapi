#!/bin/bash
# test_media.sh - Test Media Upload API

BASE_URL="${TEST_BASE_URL:-https://dev.davidfruin.com}"
EMAIL="${TEST_EMAIL:?Set TEST_EMAIL environment variable}"
PASSWORD="${TEST_PASSWORD:?Set TEST_PASSWORD environment variable}"

get_fresh_jwt() {
  # Logout first to clear old JWT, then login fresh
  JWT_OLD=$(curl -s -X POST "$BASE_URL/api.php" \
    --data-urlencode "action=login" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" | grep -o '"jwt":"[^"]*"' | cut -d'"' -f4)
  
  curl -s -X POST "$BASE_URL/api.php" -H "Authorization: Bearer $JWT_OLD" \
    --data-urlencode "action=logout" > /dev/null
  
  sleep 0.1
  
  JWT=$(curl -s -X POST "$BASE_URL/api.php" \
    --data-urlencode "action=login" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" | grep -o '"jwt":"[^"]*"' | cut -d'"' -f4)
  
  echo "$JWT"
}

echo "=== Media API Tests ==="
echo ""

# Test 1: uploadMedia without auth
echo "Test 1: uploadMedia without auth"
RESULT=$(curl -s -X POST "$BASE_URL/media.php" \
  -F "action=uploadMedia" \
  -F "file=@/etc/passwd")
if echo "$RESULT" | grep -q "Unauthorized"; then
  echo "✓ PASS: Returns 401 without auth"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 2: uploadMedia with invalid file type
JWT=$(get_fresh_jwt)
echo "Test 2: uploadMedia with invalid file type"
RESULT=$(curl -s -X POST "$BASE_URL/media.php" \
  -H "Authorization: Bearer $JWT" \
  -F "action=uploadMedia" \
  -F "file=@/etc/passwd;filename=test.exe")
if echo "$RESULT" | grep -q "Invalid file type"; then
  echo "✓ PASS: Rejects invalid file type"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 3: uploadMedia with no file
JWT=$(get_fresh_jwt)
echo "Test 3: uploadMedia with no file"
RESULT=$(curl -s -X POST "$BASE_URL/media.php" \
  -H "Authorization: Bearer $JWT" \
  -F "action=uploadMedia")
if echo "$RESULT" | grep -q "No file uploaded"; then
  echo "✓ PASS: Returns error for no file"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 4: deleteMedia without auth
echo "Test 4: deleteMedia without auth"
RESULT=$(curl -s -X POST "$BASE_URL/media.php" \
  --data-urlencode "action=deleteMedia" \
  --data-urlencode "mediaId=1")
if echo "$RESULT" | grep -q "Unauthorized"; then
  echo "✓ PASS: Returns 401 without auth"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 5: deleteMedia with invalid mediaId
JWT=$(get_fresh_jwt)
echo "Test 5: deleteMedia with invalid mediaId"
RESULT=$(curl -s -X POST "$BASE_URL/media.php" \
  -H "Authorization: Bearer $JWT" \
  --data-urlencode "action=deleteMedia" \
  --data-urlencode "mediaId=999999")
if echo "$RESULT" | grep -q "Media not found"; then
  echo "✓ PASS: Returns 404 for invalid mediaId"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

echo "=== Backend API Tests Complete ==="
