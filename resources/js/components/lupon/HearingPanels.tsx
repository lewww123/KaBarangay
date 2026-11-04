import { createContext, type ReactNode } from 'react';
export const HearingPanels = createContext<{
    resolution?: ReactNode;
    documents?: ReactNode;
}>({});
