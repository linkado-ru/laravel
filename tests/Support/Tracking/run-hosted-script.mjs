import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

assert.equal(process.versions.node.split('.')[0], '24', 'Hosted contract tests require Node 24 on PATH.');
const fixture = readFileSync(new URL('../../Fixtures/Tracking/tracking.js', import.meta.url));
const provenance = JSON.parse(readFileSync(new URL('../../Fixtures/Tracking/provenance.json', import.meta.url)));
assert.equal(createHash('sha256').update(fixture).digest('hex'), provenance.sha256, 'Tracking fixture provenance mismatch.');

function datasetFromHtml(html) {
    const dataset = {};
    for (const [, name, value] of html.matchAll(/\bdata-([a-z-]+)="([^"]*)"/g)) {
        dataset[name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = value.replace(
            /&(amp|quot|lt|gt|apos|#\d+|#x[\da-f]+);/gi,
            (_, entity) => entity[0] === '#'
                ? String.fromCodePoint(entity[1].toLowerCase() === 'x' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10))
                : ({ amp: '&', quot: '"', lt: '<', gt: '>', apos: "'" })[entity.toLowerCase()],
        );
    }
    return dataset;
}

async function runBrowser(input) {
    const now = Date.parse('2026-09-21T00:00:00Z');
    class Clock extends Date {
        constructor(...args) { super(...(args.length ? args : [now])); }
        static now() { return now; }
    }
    const cookies = new Map(Object.entries(input.cookies ?? {}));
    const writes = [];
    const calls = [];
    const timers = new Map();
    const delays = [];
    let completeFetch;
    let aborts = 0;
    const document = {
        currentScript: input.html.includes('<script ') ? { dataset: datasetFromHtml(input.html) } : null,
        get cookie() { return [...cookies].map(([key, value]) => `${key}=${encodeURIComponent(value)}`).join('; '); },
        set cookie(value) {
            writes.push(value);
            const [pair, ...attributes] = value.split('; ');
            const separator = pair.indexOf('=');
            const key = decodeURIComponent(pair.slice(0, separator));
            if (attributes.includes('Max-Age=0')) cookies.delete(key);
            else cookies.set(key, decodeURIComponent(pair.slice(separator + 1)));
        },
    };
    const window = {
        document,
        location: { search: input.search ?? '?ref=first-partner', protocol: input.protocol ?? 'https:' },
        AbortController,
        setTimeout(callback, delay) { const id = delays.push(delay); timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            calls.push({ url, method: options.method, headers: options.headers, body: JSON.parse(options.body),
                credentials: options.credentials, referrerPolicy: options.referrerPolicy, cookiesAtFetch: Object.fromEntries(cookies) });
            return new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () => { aborts++; reject(new Error('Aborted')); });
                completeFetch = () => {
                    if (input.failure === 'network') return reject(new Error('Network unavailable'));
                    const status = input.status ?? 201;
                    resolve({ status, ok: status >= 200 && status < 300, json: async () => {
                        if (input.failure === 'json') throw new SyntaxError('Malformed JSON');
                        return Object.hasOwn(input, 'payload') ? input.payload : {
                            data: { click_id: '01K5M4W0000000000000000001', expires_at: '2026-10-01T00:00:00Z' },
                        };
                    } });
                };
            });
        },
    };
    runInNewContext(fixture.toString(), { window, URLSearchParams, Date: Clock }, { timeout: 1000 });
    const synchronousCookies = Object.fromEntries(cookies);
    const synchronousWrites = [...writes];
    if (input.failure === 'timeout') for (const callback of timers.values()) callback();
    else completeFetch?.();
    // Flush the finite promise chain, without elapsed-time sleeps or real network/timers.
    await new Promise(resolve => setImmediate(resolve));
    return { calls, synchronousCookies, synchronousWrites, cookies: Object.fromEntries(cookies), writes, delays, aborts, pendingTimers: timers.size };
}

const inputs = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(await Promise.all(inputs.map(runBrowser))));
