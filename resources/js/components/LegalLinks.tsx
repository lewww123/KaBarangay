export default function LegalLinks() {
    return (
        <nav
            aria-label="Privacy and terms"
            className="mt-5 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-xs text-gray-600"
        >
            <a
                href="/privacy-policy"
                target="_blank"
                rel="noopener noreferrer"
                className="underline underline-offset-4 hover:text-[#3b6fa8]"
            >
                Privacy Policy
                <span className="sr-only"> (opens in a new tab)</span>
            </a>
            <a
                href="/terms-and-conditions"
                target="_blank"
                rel="noopener noreferrer"
                className="underline underline-offset-4 hover:text-[#3b6fa8]"
            >
                Terms and Conditions
                <span className="sr-only"> (opens in a new tab)</span>
            </a>
        </nav>
    );
}
