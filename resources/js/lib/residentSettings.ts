export type ThemeMode = 'light' | 'dark' | 'system';
export type TextSize = 'default' | 'large' | 'extra-large';

export type ResidentSettings = {
    theme: ThemeMode;
    textSize: TextSize;

    largeControls: boolean;
    highContrast: boolean;
    reduceMotion: boolean;
    simplifiedView: boolean;

    announcementNotifications: boolean;
    certificateNotifications: boolean;
    luponNotifications: boolean;
    disbursementNotifications: boolean;
};

export const SETTINGS_STORAGE_KEY = 'kabarangay_resident_settings';

export const RESIDENT_SETTINGS_EVENT = 'kabarangay-settings-changed';

export const defaultResidentSettings: ResidentSettings = {
    theme: 'light',
    textSize: 'default',

    largeControls: false,
    highContrast: false,
    reduceMotion: false,
    simplifiedView: false,

    announcementNotifications: true,
    certificateNotifications: true,
    luponNotifications: true,
    disbursementNotifications: true,
};

export function readResidentSettings(): ResidentSettings {
    if (typeof window === 'undefined') {
        return defaultResidentSettings;
    }

    try {
        const saved = window.localStorage.getItem(SETTINGS_STORAGE_KEY);

        if (!saved) {
            return defaultResidentSettings;
        }

        const parsed = JSON.parse(saved) as Partial<ResidentSettings>;

        return {
            theme: isThemeMode(parsed.theme)
                ? parsed.theme
                : defaultResidentSettings.theme,
            textSize: isTextSize(parsed.textSize)
                ? parsed.textSize
                : defaultResidentSettings.textSize,
            largeControls: readBoolean(
                parsed.largeControls,
                defaultResidentSettings.largeControls,
            ),
            highContrast: readBoolean(
                parsed.highContrast,
                defaultResidentSettings.highContrast,
            ),
            reduceMotion: readBoolean(
                parsed.reduceMotion,
                defaultResidentSettings.reduceMotion,
            ),
            simplifiedView: readBoolean(
                parsed.simplifiedView,
                defaultResidentSettings.simplifiedView,
            ),
            announcementNotifications: readBoolean(
                parsed.announcementNotifications,
                defaultResidentSettings.announcementNotifications,
            ),
            certificateNotifications: readBoolean(
                parsed.certificateNotifications,
                defaultResidentSettings.certificateNotifications,
            ),
            luponNotifications: readBoolean(
                parsed.luponNotifications,
                defaultResidentSettings.luponNotifications,
            ),
            disbursementNotifications: readBoolean(
                parsed.disbursementNotifications,
                defaultResidentSettings.disbursementNotifications,
            ),
        };
    } catch {
        return defaultResidentSettings;
    }
}

export function applyResidentSettings(
    settings: ResidentSettings,
    prefersDark = false,
) {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const isDark =
        settings.theme === 'dark' ||
        (settings.theme === 'system' && prefersDark);

    root.dataset.residentTheme = isDark ? 'dark' : 'light';
    root.dataset.residentTextSize = settings.textSize;
    root.style.colorScheme = isDark ? 'dark' : 'light';
    root.classList.toggle('resident-large-controls', settings.largeControls);
    root.classList.toggle('resident-high-contrast', settings.highContrast);
    root.classList.toggle('resident-reduce-motion', settings.reduceMotion);
    root.classList.toggle('resident-simplified-view', settings.simplifiedView);
}

export function prefersDarkMode(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches
    );
}

function isThemeMode(value: unknown): value is ThemeMode {
    return value === 'light' || value === 'dark' || value === 'system';
}

function isTextSize(value: unknown): value is TextSize {
    return value === 'default' || value === 'large' || value === 'extra-large';
}

function readBoolean(value: unknown, fallback: boolean): boolean {
    return typeof value === 'boolean' ? value : fallback;
}
