// test_auth.js - Node.js Authenticated API Tests (HTTPS)
const https = require('https');

const BASE_URL = process.env.TEST_BASE_URL || 'https://dev.davidfruin.com';
const TEST_EMAIL = process.env.TEST_EMAIL;
const TEST_PASSWORD = process.env.TEST_PASSWORD;

if (!TEST_EMAIL || !TEST_PASSWORD) {
  console.error('Error: Set TEST_EMAIL and TEST_PASSWORD environment variables');
  process.exit(1);
}

let JWT = null;

function post(action, data = {}, jwt = null) {
  return new Promise((resolve, reject) => {
    const postData = Object.assign({ action }, data);
    const body = Object.keys(postData).map(k => `${k}=${encodeURIComponent(postData[k])}`).join('&');
    
    const options = {
      hostname: BASE_URL.replace('https://', ''),
      path: '/api.php',
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body)
      }
    };
    
    if (jwt) options.headers['Authorization'] = `Bearer ${jwt}`;
    
    const req = https.request(options, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, data: JSON.parse(data) });
        } catch(e) {
          resolve({ status: res.statusCode, data: data });
        }
      });
    });
    
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

async function runTests() {
  console.log('=== Authenticated API Tests ===\n');
  console.log(`User: ${TEST_EMAIL}\n`);
  
  let passed = 0, failed = 0;
  
  // Test 1: Login
  console.log('Test 1: Login');
  let result = await post('login', { email: TEST_EMAIL, password: TEST_PASSWORD });
  console.log('  Raw result:', JSON.stringify(result.data).substring(0, 200));
  if (result.status === 200 && result.data.valid) {
    JWT = result.data.jwt;
    console.log('  ✓ PASS: Login successful');
    console.log(`  JWT captured: ${JWT ? JWT.substring(0, 30) + '...' : 'NONE'}`);
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data.message || result.data);
    failed++;
    failed++;
    console.log('  Trying without special chars...');
    
    // Try simpler password
    const simplerPwd = 'Test12345!';
    result = await post('login', { email: TEST_EMAIL, password: simplerPwd });
    console.log('  With simpler password:', result.data.message || result.data);
    failed--;
    process.exit(1);
  }
  
  // Test 2: getMyInfo
  console.log('Test 2: getMyInfo');
  result = await post('getMyInfo', {}, JWT);
  if (result.status === 200 && result.data.valid && result.data.email) {
    console.log('  ✓ PASS: getMyInfo works, email:', result.data.email);
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  // Test 3: getMyPosts
  console.log('Test 3: getMyPosts');
  result = await post('getMyPosts', { offset: 0, limit: 10 }, JWT);
  if (result.status === 200 && result.data.valid) {
    console.log('  ✓ PASS: getMyPosts works, posts:', result.data.posts?.length || 0);
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  // Test 4: post (create)
  console.log('Test 4: post (create)');
  const testPostText = 'Automated test post ' + Date.now();
  result = await post('post', { postText: testPostText }, JWT);
  if (result.status === 200 && result.data.valid) {
    console.log('  ✓ PASS: Create post works, id:', result.data.postId);
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  // Test 5: getUsers
  console.log('Test 5: getUsers');
  result = await post('getUsers', {}, JWT);
  if (result.status === 200 && result.data.valid) {
    console.log('  ✓ PASS: getUsers works, users:', result.data.users?.length || 0);
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  // Test 6: followUser (try to follow user 1 if exists)
  console.log('Test 6: followUser');
  result = await post('followUser', { userId: 2 }, JWT);
  if (result.status === 200 && result.data.valid) {
    console.log('  ✓ PASS: followUser works');
    passed++;
  } else {
    console.log('  Note: May fail if user not found -', result.data.error);
    if (result.data.error?.includes('already')) {
      console.log('  ✓ PASS: Already following (counted as pass)');
      passed++;
    } else {
      failed++;
    }
  }
  
  // Test 7: isFollowing
  console.log('Test 7: isFollowing');
  result = await post('isFollowing', { userId: 2 }, JWT);
  if (result.status === 200 && result.data.valid && result.data.following !== undefined) {
    console.log('  ✓ PASS: isFollowing works, following:', result.data.following);
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  // Test 8: getNotifications
  console.log('Test 8: getNotifications');
  result = await post('getNotifications', { offset: 0 }, JWT);
  if (result.status === 200 && result.data.valid) {
    console.log('  ✓ PASS: getNotifications works');
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  // Test 9: logout
  console.log('Test 9: logout');
  result = await post('logout', {}, JWT);
  if (result.status === 200 && result.data.valid) {
    console.log('  ✓ PASS: logout works');
    passed++;
  } else {
    console.log('  ✗ FAIL:', result.data);
    failed++;
  }
  
  console.log(`\n=== Results: ${passed} passed, ${failed} failed ===`);
  process.exit(failed > 0 ? 1 : 0);
}

runTests().catch(e => {
  console.error('Error:', e.message);
  process.exit(1);
});