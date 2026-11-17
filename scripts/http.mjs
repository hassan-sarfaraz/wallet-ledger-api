import { performance } from 'node:perf_hooks';
import http from 'node:http';
import https from 'node:https';

export const base = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const token = process.env.API_TOKEN;
if (!token) throw new Error('Set API_TOKEN in the environment.');
const transport = base.startsWith('https:') ? https : http;
const agent = new transport.Agent({ keepAlive: true, maxSockets: 100, maxFreeSockets: 16 });

export async function request(method, path, body, extraHeaders = {}) {
    const started = performance.now();
    const payload = body === undefined ? undefined : JSON.stringify(body);
    return new Promise((resolve, reject) => {
        const headers = { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json', Accept: 'application/json', ...extraHeaders };
        if (payload !== undefined) headers['Content-Length'] = Buffer.byteLength(payload);
        const outgoing = transport.request(base + path, {
            method, headers, agent, signal: AbortSignal.timeout(60000),
        }, response => {
            const chunks = [];
            response.on('data', chunk => chunks.push(chunk));
            response.on('error', reject);
            response.on('end', () => {
                try {
                    resolve({ status: response.statusCode, json: JSON.parse(Buffer.concat(chunks).toString('utf8')), ms: performance.now() - started });
                } catch (error) {
                    reject(error);
                }
            });
        });
        outgoing.on('error', reject);
        outgoing.end(payload);
    });
}

export async function pool(count, concurrency, operation) {
    agent.maxSockets = concurrency;
    let next = 0;
    const results = new Array(count);
    await Promise.all(Array.from({ length: Math.min(count, concurrency) }, async () => {
        while (next < count) {
            const index = next++;
            results[index] = await operation(index);
        }
    }));
    return results;
}

export function stats(samples) {
    const sorted = [...samples].sort((a, b) => a - b);
    const percentile = p => sorted[Math.max(0, Math.ceil(sorted.length * p) - 1)];
    return { count: sorted.length, p50_ms: +percentile(0.5).toFixed(3), p99_ms: +percentile(0.99).toFixed(3) };
}
