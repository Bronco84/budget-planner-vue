// Manual test script to verify streaming works
// Run this with: node test-streaming-manual.js

const https = require('https');
const http = require('http');

console.log('Testing Chat Streaming Endpoint...\n');

// First, we need to login to get a session cookie
const loginData = JSON.stringify({
    email: 'demo@example.com',
    password: 'password'
});

const loginOptions = {
    hostname: 'budget-planner-vue.test',
    port: 80,
    path: '/login',
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Content-Length': loginData.length,
        'Accept': 'application/json'
    }
};

console.log('Step 1: Logging in...');

const loginReq = http.request(loginOptions, (loginRes) => {
    console.log(`Login Status: ${loginRes.statusCode}`);
    
    // Get cookies from login
    const cookies = loginRes.headers['set-cookie'];
    console.log('Cookies received:', cookies ? 'Yes' : 'No');
    
    if (!cookies) {
        console.error('No cookies received from login!');
        return;
    }
    
    let body = '';
    loginRes.on('data', (chunk) => body += chunk);
    loginRes.on('end', () => {
        console.log('Login response:', body.substring(0, 200));
        
        // Now test the streaming endpoint
        console.log('\nStep 2: Testing streaming endpoint...');
        
        const streamData = JSON.stringify({
            message: 'Hello, what is my total balance?',
            conversation_id: null
        });
        
        const streamOptions = {
            hostname: 'budget-planner-vue.test',
            port: 80,
            path: '/chat/stream',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': streamData.length,
                'Accept': 'text/event-stream',
                'Cookie': cookies.join('; ')
            }
        };
        
        const streamReq = http.request(streamOptions, (streamRes) => {
            console.log(`Stream Status: ${streamRes.statusCode}`);
            console.log(`Content-Type: ${streamRes.headers['content-type']}`);
            
            if (streamRes.statusCode !== 200) {
                console.error('Stream request failed!');
                streamRes.on('data', (chunk) => console.error(chunk.toString()));
                return;
            }
            
            console.log('\nReceiving stream...\n');
            
            let chunkCount = 0;
            let fullText = '';
            
            streamRes.on('data', (chunk) => {
                const data = chunk.toString();
                console.log('Raw chunk:', data);
                
                // Parse SSE data
                const lines = data.split('\n');
                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        try {
                            const json = JSON.parse(line.slice(6));
                            console.log(`Event type: ${json.type}`);
                            
                            if (json.type === 'chunk') {
                                fullText += json.content;
                                chunkCount++;
                                process.stdout.write(json.content);
                            } else if (json.type === 'start') {
                                console.log(`Conversation ID: ${json.conversation_id}`);
                            } else if (json.type === 'done') {
                                console.log('\n\n✅ Streaming completed!');
                                console.log(`Total chunks: ${chunkCount}`);
                                console.log(`Full response: ${fullText}`);
                            } else if (json.type === 'error') {
                                console.error(`❌ Error: ${json.error}`);
                            }
                        } catch (e) {
                            // Ignore parse errors for incomplete chunks
                        }
                    }
                }
            });
            
            streamRes.on('end', () => {
                console.log('\n\nStream ended.');
                process.exit(0);
            });
        });
        
        streamReq.on('error', (e) => {
            console.error(`Stream request error: ${e.message}`);
            process.exit(1);
        });
        
        streamReq.write(streamData);
        streamReq.end();
    });
});

loginReq.on('error', (e) => {
    console.error(`Login request error: ${e.message}`);
    process.exit(1);
});

loginReq.write(loginData);
loginReq.end();

// Timeout after 30 seconds
setTimeout(() => {
    console.log('\n\n⏱️  Test timed out after 30 seconds');
    process.exit(1);
}, 30000);




