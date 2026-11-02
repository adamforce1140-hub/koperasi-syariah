@extends('auth.layout')

@section('judul', 'Atur Ulang Kata Sandi')

@section('keterangan')
    Buat kata sandi baru untuk akun Anda. Gunakan kombinasi yang tidak mudah ditebak dan tidak dipakai di layanan lain.
@endsection

@section('konten')
<form action="{{ route('password.update') }}" method="POST" class="form">
    @csrf

    <input type="hidden" name="token" value="{{ $token }}">

    <div class="kolom">
        <label for="email">Alamat Email</label>
        <div class="kendali">
            <svg class="kendali__ikon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9" />
            </svg>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}"
                   required autocomplete="email" placeholder="nama@koperasi.com">
        </div>
    </div>

    <div class="kolom">
        <label for="password">Kata Sandi Baru</label>
        <div class="kendali">
            <svg class="kendali__ikon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="4" y="10" width="16" height="11" rx="2" />
                <path d="M8 10V7a4 4 0 018 0v3" />
            </svg>
            <input type="password" id="password" name="password" required autofocus
                   autocomplete="new-password" placeholder="••••••••">
        </div>
        <p class="petunjuk">
            Minimal {{ (int) config('keamanan.password.min', 8) }} karakter
            @if (config('keamanan.password.huruf_besar')) dan mengandung huruf besar @endif
            @if (config('keamanan.password.angka')) serta minimal satu angka @endif.
        </p>
    </div>

    <div class="kolom">
        <label for="password_confirmation">Ulangi Kata Sandi Baru</label>
        <div class="kendali">
            <svg class="kendali__ikon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="4" y="10" width="16" height="11" rx="2" />
                <path d="M8 10V7a4 4 0 018 0v3" />
            </svg>
            <input type="password" id="password_confirmation" name="password_confirmation" required
                   autocomplete="new-password" placeholder="••••••••">
        </div>
    </div>

    <button type="submit" class="tombol">
        <span>Simpan Kata Sandi Baru</span>
        <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 13l4 4L19 7" />
        </svg>
    </button>
</form>
@endsection
