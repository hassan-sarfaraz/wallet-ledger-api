import { performance } from 'node:perf_hooks';
import { writeFileSync } from 'node:fs';
import { request, pool, stats } from './http.mjs';

const count = Number(process.env.REQUESTS || 10000);
const concurrency = Number(process.env.CONCURRENCY || 100);
const users = Number(process.env.USERS || 100000);
const hot = process.env.MODE === 'hot';
if (!Number.isInteger(count) || count < 100 || !Number.isInteger(concurrency) || concurrency < 1 || !Number.isInteger(users) || users < 20) {
    throw new Error('REQUESTS >= 100, CONCURRENCY >= 1 and USERS >= 20 must be integers.');
}
const samples = { credit: [], debit: [], balance: [] };
const statuses = {};
const failures = [];
const run = async (i, measure) => {
    const slot = i % 10;
    const endpoint = slot < 3 ? 'credit' : slot < 6 ? 'debit' : 'balance';
    const userId = 'seed_' + (hot ? 0 : (Math.imul(i, 7919) >>> 0) % users);
    try {
        const result = await request(endpoint === 'balance' ? 'GET' : 'POST',
            endpoint === 'balance' ? '/balance/' + userId : '/' + endpoint,
            endpoint === 'balance' ? undefined : { user_id: userId, amount: 1 });
        if (measure) {
            samples[endpoint].push(result.ms);
            statuses[result.status] = (statuses[result.status] || 0) + 1;
        }
        if (result.status !== 200 && !(endpoint === 'debit' && result.status === 409)) {
            failures.push({ endpoint, status: result.status, error: result.json.error });
        } else if (result.status === 200 && (result.json.user_id !== userId || !Number.isSafeInteger(result.json.balance) || result.json.balance < 0)) {
            failures.push({ endpoint, error: 'Invalid success response' });
        }
    } catch (error) {
        failures.push({ endpoint, error: error.message, code: error.code || error.cause?.code });
    }
};
// Warm PHP workers and database pages before measuring.
await pool(500, concurrency, i => run(i, false));
const started = performance.now();
await pool(count, concurrency, i => run(i + 500, true));
const elapsed = performance.now() - started;
const report = {
    label: process.env.LABEL || 'unlabelled', mode: hot ? 'hot' : 'mixed',
    requests: count, concurrency, users, elapsed_seconds: +(elapsed / 1000).toFixed(3),
    requests_per_second: +(count * 1000 / elapsed).toFixed(2),
    endpoints: Object.fromEntries(Object.entries(samples).map(([key, values]) => [key, values.length ? stats(values) : { count: 0 }])),
    statuses, failure_count: failures.length, failures: failures.slice(0, 10),
};
console.log(JSON.stringify(report, null, 2));
if (process.env.OUTPUT) writeFileSync(process.env.OUTPUT, JSON.stringify(report, null, 2) + '\n');
if (failures.length) process.exitCode = 1;
