<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login SIM-PD BPVP Pangkep</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background:#f4f6f9; min-height:100vh; display:grid; place-items:center; font-family:Inter,system-ui,sans-serif; }
        .login-card { width:min(400px,calc(100% - 32px)); padding:30px; border-radius:12px; background:#fff; box-shadow:0 5px 20px rgba(34,67,105,.15); border-top:5px solid #224369; }
        .logo-img { max-width:180px; max-height:100px; object-fit:contain; }
        .btn-login { background:#224369; color:#fff; padding:10px; font-weight:600; }
        .btn-login:hover { background:#1a324f; color:#fff; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <img src="{{ asset('assets/images/logo.png') }}" alt="Logo BPVP Pangkep" class="logo-img">
    </div>
    <h5 class="text-center mb-4 text-secondary">Sistem Perjalanan Dinas</h5>

    @if(session('login_error'))
        <div class="alert alert-danger py-2 text-center small">{{ session('login_error') }}</div>
    @elseif(request('pesan') === 'logout')
        <div class="alert alert-success py-2 text-center small">Berhasil logout.</div>
    @elseif(request('pesan') === 'belum_login')
        <div class="alert alert-warning py-2 text-center small">Silakan login terlebih dahulu.</div>
    @endif

    <form action="{{ route('login.attempt') }}" method="post">
        @csrf
        <div class="mb-3">
            <label for="username" class="form-label small fw-bold text-muted">USERNAME</label>
            <input id="username" type="text" name="username" value="{{ old('username') }}" class="form-control" required autofocus autocomplete="username">
        </div>
        <div class="mb-4">
            <label for="password" class="form-label small fw-bold text-muted">PASSWORD</label>
            <input id="password" type="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-login w-100">MASUK APLIKASI</button>
    </form>
    <div class="text-center mt-4 text-muted"><small>&copy; {{ date('Y') }} BPVP Pangkep</small></div>
</div>
</body>
</html>
