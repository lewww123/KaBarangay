export function plainAssistantText(text: string): string {
    return text.replace(/\*{2,3}([^\n]*?)\*{2,3}/g, '$1')
        .replace(/(^|\s)\*([^*\n]+)\*(?=\s|[.,!?:;)]|$)/g, '$1$2')
        .replace(/^[ \t]*\*\s+/gm, '• ')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/`{1,3}/g, '')
        .trim();
}
