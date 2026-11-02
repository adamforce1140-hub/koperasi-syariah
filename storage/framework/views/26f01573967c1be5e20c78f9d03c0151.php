
<script>
(function () {
    'use strict';

    const root = document.documentElement;
    const urlDenyut = root.dataset.denyutUrl;
    if (!urlDenyut) return;

    // ── Kumpulkan target yang terpasang di halaman ini ───────────────────
    // Setiap elemen boleh punya beberapa domain (dipisah spasi).
    const target = [];
    document.querySelectorAll('[data-denyut-domain]').forEach(function (el) {
        const domain = (el.dataset.denyutDomain || '').trim().split(/\s+/).filter(Boolean);
        if (domain.length) {
            target.push({
                el: el,
                domain: domain,
                url: el.dataset.denyutUrl || null,
                // Bila endpoint mengembalikan beberapa potongan sekaligus
                // (`html` berupa objek), `data-denyut-sasaran` memilih potongan
                // yang menjadi milik elemen ini.
                sasaran: el.dataset.denyutSasaran || null,
            });
        }
    });

    if (target.length === 0) return;

    // ── Konfigurasi perilaku (dapat ditimpa server saat berjalan) ────────
    // Nilai awal dibaca dari atribut <html data-denyut-*> hasil render server.
    // Server dapat memperbaruinya kapan saja lewat payload JSON
    // (`transportasi`, `detak_*`), sehingga mematikan alur cepat cukup
    // dilakukan lewat .env — tanpa menyentuh kode dan tanpa reload paksa.
    const cfg = {
        jedaAktif:   parseInt(root.dataset.denyutJedaAktif || '3', 10) || 3,
        jedaIdle:    parseInt(root.dataset.denyutJedaIdle  || '10', 10) || 10,
        sseAktif:    root.dataset.denyutSse === '1',
        tungguAktif: root.dataset.denyutTunggu === '1',
    };

    // Ada tidaknya alur cepat — SATU tempat untuk memutuskan, dipakai saat
    // memastikan transportasi aktif. Nilainya di-refresh dari payload server.
    function alurCepatAktif() { return cfg.sseAktif || cfg.tungguAktif; }

    let versiTerakhir = {};       // domain -> versi yang sudah tampil
    let versiAwalDiketahui = false;
    let sedangMenarik = false;
    let waktuTerakhirBerubah = Date.now();
    let timer = null;
    const MAX_BACKOFF = 60000;    // batas backoff saat pemanggilan gagal (ms)
    let backoffGagal = 0;

    let sumberSse = null;         // EventSource yang sedang hidup (bila ada)
    let sseHidup = false;         // apakah alur cepat SSE sedang berjalan
    let pengawas = null;          // timer pengawas status alur cepat

    function tandaiAktif() { waktuTerakhirBerubah = Date.now(); }

    // ── Deteksi otomatis status alur cepat ───────────────────────────────
    // Server menyertakan `transportasi` pada SETIAP respons JSON. Bila alur
    // cepat (SSE/long polling) dimatikan lewat variabel lingkungan saat
    // halaman sedang terbuka, klien menutup sambungan SSE dengan rapi lalu
    // beralih ke polling adaptif — tanpa reload dan tanpa kehilangan angka
    // yang sudah tampil.
    function terapkanTransportasi(info) {
        if (!info || typeof info !== 'object') return;

        const alurLama = alurCepatAktif();
        if ('sse' in info) cfg.sseAktif = info.sse === true || info.sse === '1';
        if ('tunggu' in info) cfg.tungguAktif = info.tunggu === true || info.tunggu === '1';

        // Alur cepat dimatikan → tutup SSE rapi lalu polling adaptif mengambil
        // alih. Angka yang sedang tampil tidak ikut hilang (tanpa reload).
        if (sseHidup && !cfg.sseAktif) {
            hentikanSse();
            mulaiPolling();
        }

        if (alurCepatAktif() !== alurLama) {
            document.dispatchEvent(new CustomEvent('denyut:transportasi', {
                detail: { sse: cfg.sseAktif, tunggu: cfg.tungguAktif, alurCepat: alurCepatAktif() },
            }));
        }
    }

    function hentikanSse() {
        if (sumberSse) {
            try { sumberSse.close(); } catch (e) { /* abaikan */ }
            sumberSse = null;
        }
        sseHidup = false;
        if (pengawas) {
            clearTimeout(pengawas);
            pengawas = null;
        }
    }

    // ── Terapkan perubahan: muat ulang hanya bagian terdampak ────────────
    function terapkan(versiBaru) {
        const berubah = jujur(versiBaru);

        // Selalu simpan versi terbaru supaya perbandingan berikutnya akurat.
        versiTerakhir = versiBaru;

        if (!versiAwalDiketahui) {
            // Pemanggilan pertama hanya menyelaraskan baseline; tidak ada
            // penyegaran agar halaman tidak berkedip tanpa perubahan nyata.
            versiAwalDiketahui = true;
            return false;
        }

        if (berubah.length === 0) return false;

        tandaiAktif();

        // Beberapa elemen bisa menunjuk endpoint ringkas yang sama (mis. kartu
        // statistik dan tabel memakai satu endpoint). Kumpulkan per-URL agar
        // SATU perubahan hanya menembak SATU permintaan per endpoint.
        const perUrl = new Map();

        target.forEach(function (t) {
            // Apakah salah satu domain elemen ini berubah?
            if (!t.domain.some(function (d) { return berubah.indexOf(d) !== -1; })) return;

            if (!t.url) {
                // Tidak ada endpoint ringkas → beri tahu halaman agar memutuskan
                // sendiri (mis. reload penuh sebagai fallback aman).
                document.dispatchEvent(new CustomEvent('denyut:berubah', {
                    detail: { versi: versiBaru, domain: berubah, sumber: t.el },
                }));
                return;
            }

            if (!perUrl.has(t.url)) perUrl.set(t.url, []);
            perUrl.get(t.url).push(t);
        });

        perUrl.forEach(function (daftar, url) {
            tarikRingkas(url, daftar);
        });

        return true;
    }

    // Domain mana yang versinya berbeda dari yang tersimpan.
    function jujur(versiBaru) {
        const daftar = [];
        Object.keys(versiBaru || {}).forEach(function (d) {
            if (versiTerakhir[d] !== versiBaru[d]) daftar.push(d);
        });
        return daftar;
    }

    // ── Ambil potongan HTML/JSON ringkas via endpoint halaman ────────────
    // `daftar` = semua elemen yang menunjuk URL yang sama, sehingga satu
    // permintaan melayani beberapa potongan sekaligus.
    async function tarikRingkas(urlStr, daftar) {
        const url = new URL(urlStr, window.location.origin);

        try {
            const res = await fetch(url.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            daftar.forEach(function (t) {
                // Klien boleh menyerahkan HTML siap-pasang lewat `html` (string),
                // beberapa potongan sekaligus (`html` objek — dipilih lewat
                // `data-denyut-sasaran`), atau membiarkan halaman merakitnya
                // sendiri lewat event.
                if (typeof data.html === 'string') {
                    t.el.innerHTML = data.html;
                } else if (data.html && typeof data.html === 'object' && t.sasaran && typeof data.html[t.sasaran] === 'string') {
                    t.el.innerHTML = data.html[t.sasaran];
                }

                t.el.dispatchEvent(new CustomEvent('denyut:berubah', {
                    bubbles: true,
                    detail: { versi: versiTerakhir, data: data },
                }));
            });
        } catch (e) {
            // Gagal memuat potongan tidak boleh menjatuhkan halaman; angka lama
            // tetap tampil dan percobaan berikutnya akan memperbaikinya.
            daftar.forEach(function (t) {
                t.el.dispatchEvent(new CustomEvent('denyut:gagal', {
                    bubbles: true,
                    detail: { kesalahan: e.message },
                }));
            });
        }
    }

    // ── Transportasi 1: SSE ──────────────────────────────────────────────
    function mulaiSse() {
        if (!cfg.sseAktif || typeof EventSource === 'undefined') return false;

        try {
            const es = new EventSource(urlDenyut + (urlDenyut.indexOf('?') === -1 ? '?' : '&') + 'stream=1');
            sumberSse = es;
            sseHidup = true;

            es.addEventListener('siap', function (ev) {
                backoffGagal = 0;
                const d = bongkar(ev);
                terapkanTransportasi(d.transportasi);
                terapkan(d.versi || {});
            });

            es.addEventListener('denyut', function (ev) {
                const d = bongkar(ev);
                terapkanTransportasi(d.transportasi);
                terapkan(d.versi || {});
            });

            es.addEventListener('putus', function () {
                // Server menutup wajar; EventSource akan menyambung ulang sendiri.
            });

            es.onerror = function () {
                // Bila alur cepat baru saja dimatikan lewat env, jangan biarkan
                // EventSource berputar menyambung ulang tanpa guna.
                if (!cfg.sseAktif) {
                    hentikanSse();
                    mulaiPolling();
                    return;
                }
                // Selain itu EventSource menyambung ulang sendiri; aktifkan
                // polling adaptif sebagai pengaman paralel bila gagal terus.
                mulaiPolling();
            };

            return true;
        } catch (e) {
            sseHidup = false;
            sumberSse = null;
            return false;
        }
    }

    function bongkar(ev) {
        try {
            return JSON.parse(ev.data) || {};
        } catch (e) {
            return {};
        }
    }

    function normalisasi(ev) {
        return bongkar(ev).versi || {};
    }

    // ── Transportasi 2/3: long polling & polling adaptif ─────────────────
    function mulaiPolling() {
        if (timer) return;

        const tick = async function () {
            if (document.hidden) {
                // Tab tidak dilihat → hemat sumber daya, cek jauh lebih jarang.
                timer = setTimeout(tick, Math.max(cfg.jedaIdle, 20) * 1000);
                return;
            }

            const base = (Date.now() - waktuTerakhirBerubah < 15000)
                ? cfg.jedaAktif
                : cfg.jedaIdle;

            sedangMenarik = true;
            try {
                const res = await fetch(urlDenyut, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                backoffGagal = 0;

                if (data.versi) terapkan(data.versi);

                // Status alur cepat bisa berubah kapan saja (mis. admin
                // mematikan DENYUT_SSE lalu menjalankan config:clear).
                // Klien menyesuaikan diri sendiri dari payload ini.
                terapkanTransportasi(data.transportasi);

                if (data.detak_aktif) cfg.jedaAktif = parseInt(data.detak_aktif, 10) || cfg.jedaAktif;
                if (data.detak_idle)  cfg.jedaIdle  = parseInt(data.detak_idle, 10)  || cfg.jedaIdle;

                timer = setTimeout(tick, Math.max(base, 2) * 1000);
            } catch (e) {
                // Gagal koneksi → backoff eksponensial agar server tidak
                // dibanjiri permintaan saat sedang bermasalah.
                backoffGagal = Math.min((backoffGagal || 1000) * 2, MAX_BACKOFF);
                document.dispatchEvent(new CustomEvent('denyut:gagal', {
                    detail: { kesalahan: e.message, backoff: backoffGagal },
                }));
                timer = setTimeout(tick, backoffGagal);
            } finally {
                sedangMenarik = false;
            }
        };

        timer = setTimeout(tick, 1500);
    }

    // ── Pengawas status alur cepat ───────────────────────────────────────
    // Satu permintaan JSON ringan (TANPA database) untuk memeriksa apakah alur
    // cepat (SSE/long polling) masih diizinkan server. Bila operator
    // mematikannya lewat variabel lingkungan, klien menutup SSE dan beralih ke
    // polling adaptif — tanpa reload halaman dan tanpa kedip.
    function jadwalkanPengawas() {
        if (pengawas) clearTimeout(pengawas);

        pengawas = setTimeout(async function () {
            pengawas = null;

            // SSE sudah tidak berjalan (mis. sudah turun ke polling) → berhenti.
            if (!sseHidup) return;

            try {
                const res = await fetch(urlDenyut, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                if (res.ok) {
                    const data = await res.json();
                    terapkanTransportasi(data.transportasi);
                    if (data.versi) terapkan(data.versi);
                }
            } catch (e) {
                // Senyap — ini hanya pemeriksaan latar, tidak boleh mengganggu.
            }

            if (sseHidup) jadwalkanPengawas();
        }, Math.max(cfg.jedaIdle, 10) * 1000);
    }

    // ── Pemicu tambahan: tab kembali aktif ───────────────────────────────
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            // Cek segera setelah tab terlihat lagi (mis. pengguna pindah tab
            // dan kembali) supaya angka tidak menunggu interval berikutnya.
            if (!timer) mulaiPolling();
        }
    });

    // ── Jalankan ─────────────────────────────────────────────────────────
    const sseBerjalan = mulaiSse();
    if (!sseBerjalan) {
        // SSE tidak aktif (dimatikan lewat env, atau peramban tanpa
        // EventSource) → polling adaptif langsung mengambil alih.
        mulaiPolling();
    } else {
        // SSE berjalan; pasang pengawas agar pemadaman alur cepat lewat env
        // tetap terdeteksi tanpa menunggu pengguna me-refresh.
        jadwalkanPengawas();
    }

    // Ekspos API kecil untuk halaman yang ingin memicu penyegaran manual.
    window.Denyut = {
        segarkan: function () { mulaiPolling(); },
        versi: function () { return versiTerakhir; },
        transportasi: function () {
            return { sse: cfg.sseAktif, tunggu: cfg.tungguAktif, sseHidup: sseHidup };
        },
    };
})();
</script>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\partials\denyut.blade.php ENDPATH**/ ?>