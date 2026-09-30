#!/bin/bash
# test_api_auth.sh - Test authenticated API endpoints
# Requires a test user to exist in database

BASE_URL="https://dev.davidfruin.com"
TEST_EMAIL="testuser@test.com"
TEST_PASSWORD="Test123!"

# Helper function to login and get cookie
login() {
  COOKIE=$(mktemp)
  RESULT=$(curl -s -c "$COOKIE" -X POST "$BASE_URL/api.php" \
    -d "action=login" \
    -d "email=$TEST_EMAIL" \
    -d "password=$TEST_PASSWORD")
  echo "$COOKIE"
}

echo "=== Authenticated API Tests ==="
echo ""

# Test: Login
echo "Test: Login"
COOKIE=$(mktemp)
RESULT=$(curl -s -c "$COOKIE" -X POST "$BASE_URL/api.php" \
  -d "action=login" \
  -d "email=$TEST_EMAIL" \
  -d "password=$TEST_PASSWORD")

if echo "$RESULT" | grep -q '"valid":true'; then
  echo "✓ PASS: Login successful"
  
  # Test: getMyInfo
  echo "Test: getMyInfo"
  RESULT=$(curl -s -b "$COOKIE" -X POST "$BASE_URL/api.php" -d "action=getMyInfo")
  if echo "$RESULT" | grep -q '"valid":true'; then
    echo "✓ PASS: getMyInfo works"
  else
    echo "✗ FAIL: $RESULT"
  fi
  
  # Test: getMyPosts
  echo "Test: getMyPosts"
  RESULT=$(curl -s -b "$COOKIE" -X POST "$BASE_URL/api.php" -d "action=getMyPosts")
  if echo "$RESULT" | grep -q '"valid":true'; then
    echo "✓ PASS: getMyPosts works"
  else
    echo "✗ FAIL: $RESULT"
  fi
  
  # Test: post (create)
  echo "Test: post (create)"
  RESULT=$(curl -s -b "$COOKIE" -X POST "$BASE_URL/api.php" \
    -d "action=post" \
    -d "postText=Test post from automated test")
  if echo "$RESULT" | grep -q '"valid":true'; then
    echo "✓ PASS: Create post works"
  else
    echo "✗ FAIL: $RESULT"
  fi
  
  # Test: getUsers
  echo "Test: getUsers"
  RESULT=$(curl -s -b "$COOKIE" -X POST "$BASE_URL/api.php" -d "action=getUsers")
  if echo "$RESULT" | grep -q '"valid":true'; then
    echo "✓ PASS: getUsers works"
  else
    echo "✗ FAIL: $RESULT"
  fi
  
  # Test: logout
  echo "Test: logout"
  RESULT=$(curl -s -b "$COOKIE" -X POST "$BASE_URL/api.php" -d "action=logout")
  if echo "$RESULT" | grep -q '"valid":true'; then
    echo "✓ PASS: Logout works"
  else
    echo "✗ FAIL: $RESULT"
  fi
  
  rm "$COOKIE"
else
  echo "✗ FAIL: Login failed - $RESULT"
  echo "Create test user manually first"
fi

echo ""
echo "=== Auth Tests Complete ==="