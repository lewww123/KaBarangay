<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title>Temporarily unavailable · KaBarangay</title>
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
            <h1>The barangay data service is temporarily unavailable</h1>
            <p class="note">
                KaBarangay reached you, but it could not reach the barangay
                records right now. This is a temporary connection problem, not
                a fault in your request. Nothing you submitted was lost, and
                your saved pages and queued offline requests are unaffected.
            </p>
            <p>
                <a href="{{ $retry }}">Try again</a>
                ·
                <a href="/offline.html">Open saved pages on this device</a>
            </p>
            <p>
                <small
                    >Requests are kept on this device and sent automatically
                    once the data service returns.</small
                >
            </p>
        </main>
    </body>
</html>
