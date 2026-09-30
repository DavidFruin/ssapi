#!/bin/bash
# test_api.sh - Test Simple Social API

BASE_URL="https://dev.davidfruin.com"

echo "=== Simple Social API Tests ==="
echo ""

# Test 1: Login with invalid credentials
echo "Test 1: Login with invalid credentials"
RESULT=$(curl -s -X POST "$BASE_URL/api.php" -d "action=login" -d "email=test@test.com" -d "password=wrong")
if echo "$RESULT" | grep -q "Invalid"; then
  echo "✓ PASS: Returns error for invalid credentials"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 2: Register - send OTP with invalid email
echo "Test 2: Register - send OTP with invalid email"
RESULT=$(curl -s -X POST "$BASE_URL/api.php" -d "action=sendRegisterOTP" -d "email=invalid")
if echo "$RESULT" | grep -q "Valid email"; then
  echo "✓ PASS: Returns error for invalid email"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 3: getMyInfo without auth
echo "Test 3: getMyInfo without auth"
RESULT=$(curl -s -X POST "$BASE_URL/api.php" -d "action=getMyInfo")
if echo "$RESULT" | grep -q "Unauthorized"; then
  echo "✓ PASS: Returns 401 without auth"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 4: getUsers without auth
echo "Test 4: getUsers without auth"
RESULT=$(curl -s -X POST "$BASE_URL/api.php" -d "action=getUsers")
if echo "$RESULT" | grep -q "Unauthorized"; then
  echo "✓ PASS: Returns 401 without auth"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 5: Missing action
echo "Test 5: Missing action"
RESULT=$(curl -s -X POST "$BASE_URL/api.php" -d "action=")
if echo "$RESULT" | grep -q "Missing action"; then
  echo "✓ PASS: Returns error for missing action"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 6: Unknown action (returns message "Unknown action" - security by default)
echo "Test 6: Unknown action"
RESULT=$(curl -s -X POST "$BASE_URL/api.php" -d "action=unknownAction")
if echo "$RESULT" | grep -q "Unknown action"; then
  echo "✓ PASS: Returns error for unknown action"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

# Test 7: Method not allowed (GET)
echo "Test 7: Method not allowed (GET)"
RESULT=$(curl -s "$BASE_URL/api.php")
if echo "$RESULT" | grep -q "Method not allowed"; then
  echo "✓ PASS: Returns 405 for GET"
else
  echo "✗ FAIL: $RESULT"
fi
echo ""

echo "=== Tests Complete ==="