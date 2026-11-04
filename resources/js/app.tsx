import { createInertiaApp } from '@inertiajs/react';
import OfflineSupport from '@/components/OfflineSupport';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    withApp: (app, { page }) => (
        <>
            {app}
            <OfflineSupport initialPage={page} />
        </>
    ),
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        delay: 0,
        color: '#4B5563',
    },
});
