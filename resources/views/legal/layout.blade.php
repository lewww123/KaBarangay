<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description')">
    <title>@yield('title') · KaBarangay</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root{color-scheme:light;--green:#315b30;--ink:#19291e;--muted:#526057;--line:#dce3d8}
        *{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:24px}body{margin:0;background:#f7f6f0;color:var(--ink);font:16px/1.75 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        a{color:var(--green);text-underline-offset:4px}a:focus-visible{outline:3px solid #b17b18;outline-offset:4px;border-radius:2px}.skip{position:absolute;left:16px;top:-90px;padding:10px;background:white}.skip:focus{top:10px}
        header{background:var(--green);color:white}header .wrap{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:20px 28px}header a{color:white}.brand{text-decoration:none;font-weight:750;font-size:21px}.brand small{display:block;font-size:12px;font-weight:400;opacity:.85}
        .wrap{max-width:1120px;margin:auto}.page{display:grid;grid-template-columns:230px minmax(0,1fr);gap:40px;padding:44px 28px 64px}.eyebrow{color:var(--green);font-weight:700;font-size:12px;letter-spacing:.12em;text-transform:uppercase}h1{font-size:clamp(30px,5vw,44px);line-height:1.15;letter-spacing:-.04em;margin:12px 0 16px}h2{font-size:21px;line-height:1.4;margin:0 0 12px;letter-spacing:-.025em}p{margin:0 0 16px}li{margin:6px 0}ul{padding-left:23px;margin:0 0 16px}.intro{font-size:17px;color:var(--muted)}.date{font-size:13px;color:var(--muted);margin:20px 0 28px}.notice{border-left:3px solid var(--green);background:#edf3e9;padding:16px 20px;border-radius:0 10px 10px 0;margin-bottom:30px}.notice p:last-child{margin:0}
        aside nav{position:sticky;top:24px}aside h2{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted)}aside ol{list-style:none;padding:0;margin:16px 0}aside li{margin:0}aside a{display:block;padding:7px 0;font-size:14px;text-decoration:none}aside a:hover{text-decoration:underline}.document{background:white;border:1px solid var(--line);border-radius:18px;padding:36px;box-shadow:0 3px 12px #19291e05}section+section{border-top:1px solid var(--line);padding-top:26px;margin-top:26px}.related{margin-top:26px;padding-top:22px;border-top:1px solid var(--line);font-size:14px}footer{border-top:1px solid var(--line);padding:22px 28px;color:var(--muted);font-size:13px}footer .wrap{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}footer nav{display:flex;gap:20px;flex-wrap:wrap}
        @media(max-width:760px){.page{grid-template-columns:1fr;gap:20px;padding:28px 18px 40px}.document{padding:24px 20px}aside nav{position:static}aside ol{display:flex;flex-wrap:wrap;gap:4px 16px}aside a{padding:3px 0}header .wrap{padding:16px 18px}header .back{font-size:13px}}
        @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}@media print{header,aside,footer,.related,.skip{display:none}.page{display:block;padding:0}.document{border:0;padding:0;box-shadow:none}body{background:white;color:black;font-size:11pt}h1{font-size:28pt}section{break-inside:avoid}a{color:inherit}}
    </style>
</head>
<body>
    <a class="skip" href="#content">Diretso sa nilalaman</a>
    <header><div class="wrap"><a class="brand" href="/resident/login">KaBarangay<small>Mga serbisyo ng barangay, mas abot-kamay</small></a><a class="back" href="/resident/login">Bumalik sa login</a></div></header>
    <div class="wrap page">
        <aside><nav aria-label="Mga seksiyon"><h2>Sa pahinang ito</h2>@yield('contents')</nav></aside>
        <main id="content" class="document">
            <span class="eyebrow">KaBarangay · Impormasyon sa paggamit</span>
            <h1>@yield('title')</h1>
            <p class="intro">@yield('intro')</p>
            <p class="date">Huling na-update: <time datetime="2026-11-03">3 Nobyembre 2026</time></p>
            @yield('content')
            <div class="related">@yield('related')</div>
        </main>
    </div>
    <footer><div class="wrap"><span>KaBarangay · Para sa mga residente at kawani ng barangay</span><nav aria-label="Mga patakaran"><a href="/privacy-policy">Privacy Policy</a><a href="/terms-and-conditions">Terms and Conditions</a></nav></div></footer>
</body>
</html>
