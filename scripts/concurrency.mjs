import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { request, pool, stats } from './http.mjs';

const prefix = 'test_' + randomUUID().replaceAll('-', '');
const summary = {};
const user = prefix + '_credit';
const concurrency = Number(process.env.CONCURRENCY || 100);
if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 1000) throw new Error('CONCURRENCY must be an integer from 1 through 1000.');
// Race first-user creation; use CONCURRENCY=1000 for the simultaneous burst.
const credits = await pool(1000, concurrency, () => request('POST', '/credit', { user_id: user, amount: 100 }, { Connection: 'close' }));
for (const result of credits) assert.equal(result.status, 200, JSON.stringify(result.json));
assert.deepEqual(credits.map(r => r.json.balance).sort((a,b) => a-b), Array.from({ length: 1000 }, (_, i) => (i + 1) * 100));
assert.equal((await request('GET', '/balance/' + user)).json.balance, 100000);
summary.concurrent_credits = { passed: true, concurrency, ...stats(credits.map(r => r.ms)) };

for (let iteration = 0; iteration < 20; iteration++) {
    const userId = prefix + '_race_' + iteration;
    assert.equal((await request('POST', '/credit', { user_id: userId, amount: 100 })).status, 200);
    const results = await Promise.all(Array.from({ length: 2 }, () => request('POST', '/debit', { user_id: userId, amount: 60 })));
    assert.deepEqual(results.map(r => r.status).sort(), [200, 409]);
    const balance = await request('GET', '/balance/' + userId);
    assert.equal(balance.status, 200);
    assert.equal(balance.json.balance, 40);
}
summary.double_debit = { passed: true, repetitions: 20 };

const mixedUser = prefix + '_mixed';
assert.equal((await request('POST', '/credit', { user_id: mixedUser, amount: 1000 })).status, 200);
const mixed = await pool(1000, 100, i => request('POST', i % 2 ? '/credit' : '/debit', { user_id: mixedUser, amount: 1 }));
for (const result of mixed) assert.equal(result.status, 200, JSON.stringify(result.json));
assert.equal((await request('GET', '/balance/' + mixedUser)).json.balance, 1000);
summary.mixed_writes = { passed: true, ...stats(mixed.map(r => r.ms)) };

const visibilityUser = prefix + '_visible';
for (let i = 1; i <= 20; i++) {
    const write = await request('POST', '/credit', { user_id: visibilityUser, amount: 1 });
    assert.equal(write.status, 200);
    const read = await request('GET', '/balance/' + visibilityUser);
    assert.equal(read.status, 200);
    assert.equal(read.json.balance, i);
}
summary.read_your_writes = { passed: true, repetitions: 20 };
console.log(JSON.stringify(summary, null, 2));
if (process.env.OUTPUT) writeFileSync(process.env.OUTPUT, JSON.stringify(summary, null, 2) + '\n');
