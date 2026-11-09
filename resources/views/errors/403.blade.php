<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title>Access Denied · KaBarangay</title>
        <style>
            body {
                margin: 0;
                background: #eef1f5;
                color: #444a52;
                font:
                    16px/1.7 system-ui,
                    sans-serif;
            }
            main {
                max-width: 640px;
                margin: 48px auto;
                padding: 28px;
            }
            h1 {
                line-height: 1.2;
            }
            a {
                color: #3b6fa8;
                text-decoration: underline;
            }
            .note {
                padding: 18px;
                background: #eaf1f8;
                border: 1px solid #dfe4ea;
                border-radius: 12px;
            }
            small {
                display: block;
                color: #6e747c;
            }
        </style>
    </head>
    <body>
        <main>
            <p>KaBarangay</p>
            <h1>Access Restricted</h1>
            <p class="note">
                Your currently selected staff assignment does not authorize access to this portal. If you hold assignments in other departments, please switch to or log in with that assignment.
            </p>
            <p>
                <a href="{{ route('login') }}">Return to Staff Login</a>
            </p>
            <p>
                <small>If you believe you should have access to this portal, contact your barangay administrator.</small>
            </p>
        </main>
    </body>
</html>
