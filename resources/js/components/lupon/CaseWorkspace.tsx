import { useState, type ReactNode } from 'react';
import { HearingPanels } from './HearingPanels';
import { caseSections, type CaseSection } from './caseGuide';
import type { LuponDocument, LuponWorkflow } from './types';

export default function CaseWorkspace({
    overview,
    panels,
}: {
    workflow: LuponWorkflow;
    hearings: { id: number; status: string }[];
    documents: LuponDocument[];
    hasSettlement: boolean;
    onSchedule: () => void;
    overview: ReactNode;
    panels: Record<
        'hearings' | 'documents' | 'resolution' | 'details' | 'history',
        ReactNode
    >;
}) {
    const [section, setSection] = useState<CaseSection>('overview');
    return (
        <>
            <nav
                role="tablist"
                aria-label="Case workspace"
                className="flex gap-1 overflow-x-auto rounded-xl border border-stone-200 bg-white p-1.5"
                onKeyDown={(event) => {
                    const focused = (event.target as HTMLElement).closest(
                        '[role=tab]',
                    )?.id;
                    const index = caseSections.findIndex((item) =>
                        focused
                            ? `case-tab-${item.id}` === focused
                            : item.id === section,
                    );
                    const next =
                        event.key === 'ArrowRight'
                            ? (index + 1) % caseSections.length
                            : event.key === 'ArrowLeft'
                              ? (index + caseSections.length - 1) %
                                caseSections.length
                              : event.key === 'Home'
                                ? 0
                                : event.key === 'End'
                                  ? caseSections.length - 1
                                  : -1;
                    if (next < 0) return;
                    event.preventDefault();
                    setSection(caseSections[next].id);
                    document
                        .getElementById(`case-tab-${caseSections[next].id}`)
                        ?.focus();
                }}
            >
                {caseSections.map((item) => (
                    <button
                        key={item.id}
                        id={`case-tab-${item.id}`}
                        role="tab"
                        type="button"
                        aria-selected={section === item.id}
                        aria-controls={`case-panel-${item.id}`}
                        tabIndex={section === item.id ? 0 : -1}
                        onClick={() => setSection(item.id)}
                        className={`rounded-lg px-4 py-2.5 text-sm font-semibold ${section === item.id ? 'bg-[#3f6f3d] text-white' : 'text-stone-600 hover:bg-stone-100'}`}
                    >
                        {item.label}
                    </button>
                ))}
            </nav>
            <section
                id="case-panel-overview"
                role="tabpanel"
                aria-labelledby="case-tab-overview"
                hidden={section !== 'overview'}
                className="space-y-4"
            >
                {overview}
                {panels.details}
            </section>
            {caseSections
                .filter((item) => item.id !== 'overview')
                .map((item) => (
                    <section
                        key={item.id}
                        id={`case-panel-${item.id}`}
                        role="tabpanel"
                        aria-labelledby={`case-tab-${item.id}`}
                        hidden={section !== item.id}
                        className="space-y-4"
                    >
                        <HearingPanels.Provider value={{ resolution: panels.resolution, documents: panels.documents }}>
                            {panels[item.id as keyof typeof panels]}
                        </HearingPanels.Provider>
                    </section>
                ))}
        </>
    );
}
