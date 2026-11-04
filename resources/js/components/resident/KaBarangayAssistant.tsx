import { usePage } from '@inertiajs/react';
import BarangayChatbot from './BarangayChatbot';

export default function KaBarangayAssistant() {
    const { chatbotStorageKey } = usePage<{
        chatbotStorageKey?: string | null;
    }>().props;
    return <BarangayChatbot floating storageKey={chatbotStorageKey ?? null} />;
}
