import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
const source = await readFile(new URL('../../resources/js/components/lupon/caseGuide.ts', import.meta.url), 'utf8');
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const { caseGuide, caseSections, recordedStages, hearingSummary } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`);
const workflow = { status: 'mediation_active', transitions: ['pangkat_formed', 'settled', 'dismissed'], milestones: { mediation_started_at: '2026-11-02' }, events: [{ to: 'filed' }] };

test('hearing summary uses recorded schedules, phase and dates without changing source', () => {
    const hearings = [
        {id:3, phase:'Pangkat', date:'2026-11-05', time:'09:00', status:'Scheduled'},
        {id:2, phase:'Kapitan', date:'2026-11-04', time:'09:00', status:'Held'},
        {id:1, phase:'Kapitan', date:'2026-11-06', time:'10:00', status:'Rescheduled'},
    ];
    assert.equal(hearingSummary(hearings, 'Kapitan').id, 1);
    assert.equal(hearingSummary(hearings).id, 3);
    assert.deepEqual(hearings.map(hearing=>hearing.id), [3,2,1]);
    assert.equal(hearingSummary([]), undefined);
    assert.equal(hearingSummary(hearings.map(hearing=>({...hearing,status:'Held'}))).id, 1);
});
test('case details, hearings and history are the only workspace tabs', () => assert.deepEqual(caseSections.map(s => s.label), ['Case details', 'Hearings', 'History']));
test('only recorded events and milestones establish earlier records', () => {
    const stages = recordedStages(workflow);
    assert.equal(stages.find(s => s.status === 'filed').state, 'recorded');
    assert.equal(stages.find(s => s.status === 'mediation_active').state, 'current');
    assert.equal(stages.find(s => s.status === 'pangkat_formed').state, 'verification');
    assert.equal(stages.find(s => s.status === 'conciliation_active').state, 'unavailable');
});
test('current outcome does not fabricate missing earlier stages', () => {
    const stages = recordedStages({ ...workflow, status: 'dismissed', transitions: [], events: [], milestones: {} });
    assert.equal(stages.find(s => s.status === 'mediation_active').state, 'unavailable');
});
test('record counts never establish attendance or completion', () => {
    for (const count of [0, 1, 3, 8]) {
        const guide = caseGuide('mediation_active', Array.from({ length: count }, (_, id) => ({ id, status: 'Held' })), [], false);
        assert.equal(guide.section, 'overview');
        assert.equal(guide.schedule, undefined);
        assert.doesNotMatch(guide.description, /attendance.*recorded|requirements complete/i);
    }
});
test('schedules and documents are navigation, not readiness decisions', () => {
    assert.equal(caseGuide('mediation_active', [{ id: 1, status: 'Scheduled' }], [], false).section, 'hearings');
    const guide = caseGuide('mediation_active', [], [{ id: 2, status: 'ready', servedAt: null }], false);
    assert.equal(guide.section, 'hearings');
    assert.match(guide.description, /does not establish service/);
});
