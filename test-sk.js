const https = require('https');

// Replace with your actual Stripe secret key
const sk = 'sk_test_YOUR_KEY_HERE';
const cc = '4242424242424242|12|2028|123'; // Test card

const parts = cc.split('|');
const data = `type=card&card[number]=${parts[0]}&card[exp_month]=${parts[1]}&card[exp_year]=${parts[2]}&card[cvc]=${parts[3]}`;

const options = {
    hostname: 'api.stripe.com',
    path: '/v1/payment_methods',
    method: 'POST',
    headers: {
        'Authorization': `Bearer ${sk}`,
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': data.length
    }
};

console.log('Testing card:', parts[0].slice(0, 6) + '******' + parts[0].slice(-4));
console.log('Making request to Stripe API...\n');

const req = https.request(options, (res) => {
    let body = '';
    res.on('data', (chunk) => body += chunk);
    res.on('end', () => {
        console.log('Status Code:', res.statusCode);
        console.log('\nResponse:');
        try {
            const json = JSON.parse(body);
            console.log(JSON.stringify(json, null, 2));
        } catch (e) {
            console.log(body);
        }
    });
});

req.on('error', (e) => {
    console.error('Error:', e.message);
});

req.write(data);
req.end();
