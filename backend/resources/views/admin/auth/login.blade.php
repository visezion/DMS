<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>DMS Admin Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Space Grotesk', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-white flex items-center justify-center p-6">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(14,165,233,.3),transparent_40%),radial-gradient(circle_at_80%_10%,rgba(249,115,22,.3),transparent_35%)]"></div>
    <div class="relative w-full max-w-md rounded-2xl border border-white/20 bg-white/10 backdrop-blur-xl p-8">
        <p class="text-xs uppercase tracking-[0.25em] text-slate-200">Windows Device Control</p>
        <h1 class="text-3xl font-bold mt-2">DMS Admin</h1>
        <p class="text-slate-200 text-sm mt-1">Sign in to control policies, software, and fleet operations.</p>

        @if($errors->any())
            <div class="mt-4 rounded-lg bg-red-500/20 border border-red-300/30 px-3 py-2 text-sm">{{ $errors->first() }}</div>
        @endif

        <form id="login-form" class="mt-6 space-y-4" method="POST" action="{{ route('admin.login.submit') }}">
            @csrf
            <div>
                <label class="text-xs uppercase tracking-wide text-slate-300">Email</label>
                <input name="email" type="email" required class="mt-1 w-full rounded-lg border border-white/30 bg-white/10 px-3 py-2" value="{{ old('email') }}" />
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-slate-300">Password</label>
                <input name="password" type="password" required class="mt-1 w-full rounded-lg border border-white/30 bg-white/10 px-3 py-2" />
            </div>
            <button id="login-submit" type="submit" class="w-full rounded-lg bg-sky-400 text-slate-950 font-semibold py-2.5 hover:bg-sky-300">Sign In</button>
        </form>

        <p class="text-xs text-slate-300 mt-4">Default seed credentials: <span class="font-semibold">admin@dms.local / ChangeMe123!</span></p>
    </div>

    <div id="login-progress-modal" class="hidden fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-6">
        <div class="w-full max-w-sm rounded-2xl border border-white/20 bg-slate-900/90 p-5">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 h-5 w-5 rounded-full border-2 border-slate-400 border-t-sky-400 animate-spin"></div>
                <div>
                    <p class="text-sm font-semibold text-white">Signing in...</p>
                    <p class="text-xs text-slate-300 mt-1">Please wait while your session is being created.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('login-form');
            const submit = document.getElementById('login-submit');
            const modal = document.getElementById('login-progress-modal');
            if (!form || !submit || !modal) return;

            form.addEventListener('submit', function () {
                modal.classList.remove('hidden');
                submit.disabled = true;
                submit.classList.add('opacity-70', 'cursor-not-allowed');
                submit.textContent = 'Signing In...';
            });
        })();
    </script>
</body>
</html>
