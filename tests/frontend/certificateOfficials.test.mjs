import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(
    new URL('../../resources/js/lib/certificateOfficials.ts', import.meta.url),
    'utf8',
);
const js = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext },
}).outputText;
const { resolveCertificateOfficials: resolve } = await import(
    `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
);
const captain = { id: 1, name: 'Default Captain', title: 'Punong Barangay' };
const alice = { id: 2, name: 'Alice Reyes', title: 'Kagawad on Health' };
const bob = { id: 3, name: 'Bob Santos', title: 'Kagawad' };
const members = [alice, bob];

void test('empty selection retains incumbent and all Kagawads', () => {
    assert.deepEqual(resolve(captain, members, ''), {
        captain,
        kagawads: members,
    });
});

void test('explicit delegation excludes selected Kagawad and duplicate names, then restores default', () => {
    const duplicate = { ...alice, id: 4, name: ' ALICE   REYES ' };
    assert.deepEqual(resolve(captain, [...members, duplicate], '2'), {
        captain: { ...alice, title: 'Barangay Kagawad, OIC' },
        kagawads: [bob],
    });
    assert.deepEqual(resolve(captain, members, ''), {
        captain,
        kagawads: members,
    });
    assert.equal(alice.title, 'Kagawad on Health');
});

void test('missing incumbent never automatically promotes a Kagawad', () => {
    assert.deepEqual(resolve(null, members, ''), {
        captain: null,
        kagawads: members,
    });
});
