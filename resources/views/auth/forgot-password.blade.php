@extends('auth.layout')

@section('judul', 'Lupa Kata Sandi')

@section('keterangan')
    Masukkan alamat email akun Anda. Kami akan mengirimkan tautan untuk mengatur ulang kata sandi.
@endsection

@section('konten')
<form action="{{ route('password.email') }}" method="POST" class="form">
    @csrf

    <div class="kolom">
        <label for="email">Alamat Email</label>
        <div class="kendali">
            <svg class="kendali__ikon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9" />
            </svg>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="email"
                   placeholder="nama@koperasi.com">
        </div>
    </div>

    <button type="submit" class="tombol">
        <span>Kirim Tautan Atur Ulang</span>
        <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h14m0 0l-6-6m6 6l-6 6" />
        </svg>
    </button>
</form>
@endsection
