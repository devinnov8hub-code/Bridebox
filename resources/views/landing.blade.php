<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BridgeBox Access</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}">
</head>
<body class="auth-body" data-screen="splash">
    <div class="auth-shell">
        <section class="screen splash" id="splash" aria-label="BridgeBox loading screen">
            <div class="logo-stack" aria-hidden="true">
                <img class="brand-logo" src="{{ asset('assets/images/bridgebox.png') }}" alt="BridgeBox logo">
            </div>
        </section>

        <section class="screen role" id="role" aria-label="Role selection">
            <div class="role-panel">
                <p class="eyebrow">{{ __('BridgeBox Access') }}</p>
                <h1>{{ __('What do you want to login as') }}</h1>
                <p class="subtext">{{ __('Choose a role to continue to your secure workspace.') }}</p>

                <div class="role-options">
                    <a class="role-option admin" href="{{ route('login', ['role' => 'admin']) }}">
                        <div class="role-icon">A</div>
                        <div class="role-text">
                            <span class="role-title">{{ __('Admin') }}</span>
                            <span class="role-sub">{{ __('Manage access and oversight') }}</span>
                        </div>
                        <span class="role-arrow">→</span>
                    </a>
                    <a class="role-option teacher" href="{{ route('login', ['role' => 'teacher']) }}">
                        <div class="role-icon">T</div>
                        <div class="role-text">
                            <span class="role-title">{{ __('Teacher') }}</span>
                            <span class="role-sub">{{ __('Prepare lessons and share') }}</span>
                        </div>
                        <span class="role-arrow">→</span>
                    </a>
                    <a class="role-option student" href="{{ route('login', ['role' => 'student']) }}">
                        <div class="role-icon">S</div>
                        <div class="role-text">
                            <span class="role-title">{{ __('Student') }}</span>
                            <span class="role-sub">{{ __('Explore content and learn') }}</span>
                        </div>
                        <span class="role-arrow">→</span>
                    </a>
                </div>
            </div>
        </section>
    </div>

    <script src="{{ asset('assets/js/landing.js') }}"></script>
    <script src="{{ asset('assets/js/offline.js') }}"></script>
</body>
</html>
