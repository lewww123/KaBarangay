import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/offline-certificates.js', 'utf8');
const match = source.match(/const money = \(fee\) => \{[\s\S]*?\n    \};/);

assert.ok(match, 'offline certificate fee formatter not found');

const money = new Function(`return (${match[0].replace('const money = ', '').replace(/;\s*$/, '')})`)();

test('offline certificate fee formatter distinguishes null, zero, and paid fees', () => {
    assert.equal(money(null), 'Not specified');
    assert.equal(money(undefined), 'Not specified');
    assert.equal(money(0), '₱0.00');
    assert.equal(money('0.00'), '₱0.00');
    assert.equal(money(50), '₱50.00');
    assert.equal(money('75.00'), '₱75.00');
});
