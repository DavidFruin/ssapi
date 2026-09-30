// test.js - Node.js API Tests (HTTPS)
const https = require('https');

const BASE_URL = 'https://dev.davidfruin.com';

function post(action, data = {}) {
  return new Promise((resolve, reject) => {
    const postData = Object.assign({ action }, data);
    const body = Object.keys(postData).map(k => `${k}=${encodeURIComponent(postData[k])}`).join('&');
    
    const options = {
      hostname: 'dev.davidfruin.com',
      path: '/api.php',
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body)
      }
    };
    
    const req = https.request(options, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ status: res.statusCode, data: JSON.parse(data) }));
    });
    
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

async function runTests() {
  console.log('=== API Unit Tests ===\n');
  let passed = 0, failed = 0;
  
  try {
    const result = await post('getMyInfo');
    if (result.status === 401 && result.data.error === 'Unauthorized') {
      console.log('✓ PASS: getMyInfo without auth returns 401');
      passed++;
    } else {
      console.log('✗ FAIL: Expected 401, got', result);
      failed++;
    }
  } catch(e) { console.log('✗ FAIL:', e.message); failed++; }
  
  try {
    const result = await post('login', { email: 'invalid@test.com', password: 'wrong' });
    if (result.status === 401 && result.data.message.includes('Invalid')) {
      console.log('✓ PASS: Invalid login returns error');
      passed++;
    } else {
      console.log('✗ FAIL:', result);
      failed++;
    }
  } catch(e) { console.log('✗ FAIL:', e.message); failed++; }
  
  try {
    const result = await post('sendRegisterOTP', { email: 'invalid' });
    if (result.status === 400 && result.data.message.includes('Valid email')) {
      console.log('✓ PASS: Invalid email returns error');
      passed++;
    } else {
      console.log('✗ FAIL:', result);
      failed++;
    }
  } catch(e) { console.log('✗ FAIL:', e.message); failed++; }
  
  console.log(`\n=== Results: ${passed} passed, ${failed} failed ===`);
  process.exit(failed > 0 ? 1 : 0);
}

runTests().catch(console.error);