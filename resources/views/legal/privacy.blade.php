@extends('legal.layout')
@section('title', 'Privacy Policy')
@section('description', 'Alamin kung paano ginagamit ang personal na impormasyon sa KaBarangay at paano makikipag-ugnayan tungkol sa iyong privacy.')
@section('intro', 'Ipinapaliwanag ng abisong ito ang pagproseso ng personal na impormasyon kapag ginagamit mo ang KaBarangay para sa mga serbisyo ng napili mong barangay.')
@section('contents')
<ol>
    <li><a href="#responsible">1. Namamahala sa impormasyon</a></li>
    <li><a href="#data">2. Impormasyong pinoproseso</a></li>
    <li><a href="#purpose">3. Layunin ng paggamit</a></li>
    <li><a href="#sharing">4. Access at mga provider</a></li>
    <li><a href="#ai">5. Kabarangay AI</a></li>
    <li><a href="#storage">6. Cookies at browser storage</a></li>
    <li><a href="#retention">7. Pag-iingat at retention</a></li>
    <li><a href="#rights">8. Mga karapatan at contact</a></li>
    <li><a href="#changes">9. Mga pagbabago</a></li>
</ol>
@endsection
@section('content')
<section id="responsible">
    <h2>1. Sino ang namamahala sa iyong impormasyon?</h2>
    <p>Ang barangay na pinili mo sa registration at ang mga awtorisadong kawani nito ang nangangasiwa sa iyong application at mga serbisyong isinusumite sa KaBarangay. Nakabatay ang access sa tungkulin at barangay assignment ng kawani.</p>
    <p>Para sa tanong tungkol sa iyong records o sa abisong ito, makipag-ugnayan sa administrator ng napili mong barangay sa pamamagitan ng barangay office. Sabihin na ang pakay mo ay tungkol sa KaBarangay at sa iyong personal na impormasyon.</p>
</section>
<section id="data">
    <h2>2. Anong impormasyon ang pinoproseso?</h2>
    <ul>
        <li><strong>Registration at profile:</strong> pangalan, petsa ng kapanganakan, kasarian, civil status, barangay, purok, tirahan, email, contact number, at iba pang hinihinging detalye sa form.</li>
        <li><strong>Pagpapatunay:</strong> mga identification document, larawan, at supporting file na isinusumite mo para sa pag-verify ng account o serbisyo.</li>
        <li><strong>Mga serbisyo:</strong> detalye ng certificate requests, layunin ng request, sector applications at membership, supporting documents, at kaugnay na payment o waiver records kapag kailangan.</li>
        <li><strong>Lupon records:</strong> impormasyong kasama sa reklamo, mga partido, ebidensiya, hearing schedules, attendance, notes, at resulta ng kasong may kaugnayan sa iyo. Maaaring may impormasyon din tungkol sa ibang taong sangkot.</li>
        <li><strong>Account at paggamit:</strong> password sa hashed form, session identifiers, reset tokens, reference numbers, timestamps, at security o audit records gaya ng login attempts at mga aksiyon sa system.</li>
    </ul>
    <p>Depende sa serbisyong ginagamit mo, maaaring may sensitibong personal na impormasyon sa mga isinusumiteng dokumento. Ibigay lamang ang impormasyong kailangan ng kaukulang form at iwasang mag-upload ng hindi kaugnay na dokumento.</p>
</section>
<section id="purpose">
    <h2>3. Bakit ginagamit ang impormasyon?</h2>
    <p>Ginagamit ito upang suriin ang registration at pagkakakilanlan, magproseso ng requests at complaints, mamahala ng appointments at hearings, magbigay ng status updates at dokumento, magpadala ng account at service emails, at magpanatili ng mga kailangang administrative at audit record.</p>
    <p>Ginagamit din ang mga tala para sa awtorisadong reports at pagpaplano ng barangay. Ang batayan ng pagproseso ay nakadepende sa serbisyo at sa naaangkop na tungkulin, obligasyon, o iba pang legal na batayan ng barangay; hiwalay na hihingin ang pahintulot kung iyon ang kinakailangang batayan. Ang pagbasa sa pahinang ito ay hindi blanket consent para sa lahat ng paggamit ng iyong data.</p>
</section>
<section id="sharing">
    <h2>4. Sino ang maaaring makatanggap o magkaroon ng access?</h2>
    <p>Maaaring makita ng awtorisadong barangay personnel ang records na kailangan sa kanilang trabaho, kabilang ang registration review, certificate processing, sector services, at Lupon proceedings. Ang paglalabas ng impormasyon sa ibang partido o ahensiya ay dapat may kaugnayan sa serbisyo o naaangkop na legal na batayan.</p>
    <p>Gumagamit ang deployment ng Render para sa hosting at PostgreSQL/Supabase infrastructure para sa database. Maaaring dumaan sa infrastructure ng mga provider na ito ang impormasyong kailangan para patakbuhin at i-store ang system. Ang mga provider ay maaaring magproseso ng data sa labas ng Pilipinas.</p>
    <p>Ang naka-configure na email provider, gaya ng Gmail/Google o Resend, ay tumatanggap ng recipient email, nilalaman ng notification, at anumang link o dokumentong kasama sa mensahe. Ang aktuwal na provider ay nakadepende sa kasalukuyang deployment configuration. Makukumpirma ito ng barangay administrator.</p>
</section>
<section id="ai">
    <h2>5. Paano ginagamit ang impormasyon sa Kabarangay AI?</h2>
    <p>Para makabuo ng AI response, ipinapadala sa Groq API ang iyong tanong, limitadong nakaraang usapan, gabay sa system, at snapshot ng mga kaugnay na record ng naka-login mong account. Maaaring kasama sa snapshot ang reference number, uri ng serbisyo, status, petsa, at hearing schedule o venue. Hindi dapat maglagay ng password, reset link, o hindi kailangang sensitibong detalye sa chat.</p>
    <p>Ang mga tanong na kinikilala ng system bilang direktang status tracking ay sinasagot mula sa account records nang hindi tumatawag sa Groq para sa sagot na iyon. Sa ibang AI questions, maaaring maisama ang record snapshot kahit hindi ka tahasang humingi ng status.</p>
    <p>Opsiyonal ang paggamit ng AI chat. Maaari mong gamitin ang mga normal na form at request pages nang hindi ito ginagamit. Ang sagot ng AI ay gabay at hindi opisyal na approval o desisyon.</p>
</section>
<section id="storage">
    <h2>6. Cookies at browser storage</h2>
    <p>Gumagamit ang system ng session at security cookies para sa pag-login at proteksiyon ng requests. Ang chat history ay naka-save rin sa local storage ng browser, na hiwalay ayon sa account, upang maibalik ang usapan pagkatapos ng reload.</p>
    <p>Gamitin ang “Bagong usapan” upang i-clear ang naka-save na usapan sa browser na iyon. Maaari mo ring i-clear ang site data sa browser settings. Hindi nito awtomatikong binubura ang opisyal na barangay records o data na naipadala na sa mga provider. Sa shared computer, mag-log out at linisin ang chat o site data bago umalis.</p>
</section>
<section id="retention">
    <h2>7. Pag-iingat at tagal ng pag-iimbak</h2>
    <p>May mga kontrol sa pag-login, role at barangay access, password hashing, at request validation ang system. Walang online system na makakagarantiya ng ganap na proteksiyon laban sa lahat ng panganib. I-report agad sa barangay administrator ang pinaghihinalaang maling access o pagkawala ng account.</p>
    <p>Walang iisang retention period na inilalapat sa lahat ng uri ng record sa abisong ito. Ang tagal ng pagpapanatili ay dapat tumugma sa layunin ng serbisyo, naaangkop na batas, records-retention requirements, at aprubadong patakaran ng barangay. Humingi sa administrator ng retention details para sa partikular mong record. Ang pagsara ng account ay hindi awtomatikong nagbubura ng mga opisyal na dokumentong kailangang panatilihin.</p>
</section>
<section id="rights">
    <h2>8. Mga karapatan at pakikipag-ugnayan</h2>
    <p>Alinsunod sa naaangkop na batas, maaari kang humiling ng impormasyon tungkol sa pagproseso, access o kopya ng iyong data, at pagwawasto ng maling detalye. Maaari ka ring humiling ng pagtutol, pagbawi ng pahintulot, o pag-block o pagbura kung naaangkop. Maaaring may limitasyon kapag kailangang panatilihin ang record o protektahan ang karapatan ng ibang tao.</p>
    <div class="notice"><p><strong>Para sa privacy concern:</strong> lumapit sa administrator ng barangay na pinili mo sa registration. Ibigay ang iyong pangalan at reference number kung mayroon, at ilarawan ang hinihinging aksiyon. Huwag ibigay ang password o reset token. Maaaring kailanganing kumpirmahin ang iyong pagkakakilanlan bago ilabas o baguhin ang record.</p></div>
    <p>Maaari ka ring makipag-ugnayan sa <a href="https://privacy.gov.ph/">National Privacy Commission</a> tungkol sa data privacy concerns. Basahin ang <a href="https://privacy.gov.ph/data-subject-rights/">gabay sa mga karapatan ng data subject</a>.</p>
</section>
<section id="changes">
    <h2>9. Mga pagbabago sa abisong ito</h2>
    <p>Ia-update ang pahinang ito kapag nagbago ang data handling o mga serbisyo. Makikita sa itaas ang petsa ng huling update. Ang pagbabagong nangangailangan ng hiwalay na abiso o pahintulot ay dapat ipaalam sa naaangkop na paraan.</p>
</section>
@endsection
@section('related')
Basahin din ang <a href="/terms-and-conditions">Terms and Conditions</a> para sa mga patakaran sa paggamit ng KaBarangay.
@endsection
