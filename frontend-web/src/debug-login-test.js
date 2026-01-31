// Debug Login Test
// Paste this in browser console to test login flow

async function debugLogin() {
    console.log('🔍 Starting login debug test...');
    
    try {
        // Test 1: API Call
        console.log('1️⃣ Testing API call...');
        const response = await fetch('http://localhost:8000/api/v1/auth/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                username: 'superadmin',
                password: 'password123'
            })
        });
        
        const data = await response.json();
        console.log('✅ API Response:', data);
        
        if (!data.success) {
            console.error('❌ API Login failed:', data);
            return;
        }
        
        // Test 2: Token Storage
        console.log('2️⃣ Testing token storage...');
        const token = data.data.access_token;
        const user = data.data.user;
        
        sessionStorage.setItem('__auth_token__', token);
        const storedToken = sessionStorage.getItem('__auth_token__');
        console.log('✅ Token stored:', !!storedToken);
        
        // Test 3: User Role Check
        console.log('3️⃣ Testing user role...');
        console.log('User role:', user.role_type);
        console.log('Expected redirect:', user.role_type === 'super_admin' ? '/super-admin/dashboard' : 'Other');
        
        // Test 4: Navigation
        console.log('4️⃣ Testing navigation...');
        if (user.role_type === 'super_admin') {
            console.log('✅ Should redirect to /super-admin/dashboard');
            // Uncomment to test actual redirect:
            // window.location.href = '/super-admin/dashboard';
        }
        
        console.log('🎉 Debug test completed successfully!');
        
    } catch (error) {
        console.error('❌ Debug test failed:', error);
    }
}

// Run the test
debugLogin();