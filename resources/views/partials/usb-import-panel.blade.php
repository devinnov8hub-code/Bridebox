{{--
    USB Content Import — reusable panel.

    Variables (with sensible defaults):
        $variant       'admin' | 'teacher' | 'student'   (default 'admin')
        $showLibrary   true | false                       (default true)
        $title         string                             (default localized)

    Behaviour by role:
      admin / teacher  -> sees drive list + Copy buttons + progress + library
      student          -> sees library only (read-only, no copy)
--}}
@php
    $variant = $variant ?? 'admin';
    $showLibrary = $showLibrary ?? true;
    $title = $title ?? __('USB Content Import');
    $isReadOnly = $variant === 'student';
    $isMini = $variant === 'teacher';
@endphp

<section class="panel">
    <div class="panel-header">
        <h4>{{ $title }}</h4>
        <span class="badge blue">{{ $isReadOnly ? __('Library') : __('Import') }}</span>
    </div>

    <div class="panel-body">
        <div class="usb-panel {{ $isMini ? 'usb-mini' : '' }}"
             data-usb-panel
             @if (! $isReadOnly)
             data-url-drives="{{ route('usb.drives') }}"
             data-url-start="{{ route('usb.start') }}"
             data-url-progress="{{ route('usb.progress') }}"
             @endif
             data-url-list="{{ route('usb.list') }}"
             data-csrf="{{ csrf_token() }}">

            @unless ($isReadOnly)
                {{-- ClamAV warning ---------------------------------- --}}
                <div class="usb-warning" data-usb-clam-warning hidden>
                    <i class="fa-solid fa-shield-halved"></i>
                    <div>
                        <strong>{{ __('Virus scanner not installed.') }}</strong>
                        {{ __('ClamAV (clamscan) was not found on this device. Files will still be filtered by extension blocklist, but full scanning is disabled. Ask your administrator to install ClamAV on the Raspberry Pi.') }}
                    </div>
                </div>

                {{-- Detected USB drives ------------------------------ --}}
                <div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
                        <p class="usb-section-title"><i class="fa-solid fa-magnifying-glass"></i> {{ __('Detected drives') }}</p>
                        <button type="button" class="btn ghost btn-small" data-usb-refresh>
                            <i class="fa-solid fa-arrows-rotate"></i> {{ __('Refresh') }}
                        </button>
                    </div>
                    <div class="usb-drives" data-usb-drives></div>
                    <div class="usb-empty" data-usb-empty>
                        <i class="fa-solid fa-circle-info"></i>
                        {{ __('No USB drive detected. Plug a flash drive into the Raspberry Pi and click Refresh.') }}
                    </div>
                </div>

                {{-- Live progress mini-screen ----------------------- --}}
                <div class="usb-progress-card" data-usb-progress hidden>
                    <div class="usb-progress-card-header">
                        <h5><i class="fa-solid fa-cloud-arrow-down"></i> {{ __('Import in progress') }}</h5>
                        <span class="usb-progress-pct" data-usb-progress-pct>0%</span>
                    </div>
                    <div class="usb-progress-bar-track">
                        <div class="usb-progress-bar-fill" data-usb-progress-bar></div>
                    </div>
                    <p class="usb-progress-msg" data-usb-progress-msg>{{ __('Preparing…') }}</p>
                    <div class="usb-progress-current">
                        <span class="usb-progress-current-file" data-usb-current-file>—</span>
                        <span class="usb-progress-current-cat" data-usb-current-cat>—</span>
                    </div>
                    <div>
                        <span class="usb-scan-pill usb-scan-pending" data-usb-scan-status>{{ __('Pending scan') }}</span>
                    </div>
                </div>
            @endunless

            {{-- Imported library --------------------------------- --}}
            @if ($showLibrary)
                <div>
                    <p class="usb-section-title" style="margin-top:.5rem;">
                        <i class="fa-solid fa-folder-open"></i> {{ __('Available content') }}
                    </p>
                    <div class="usb-library-grid" data-usb-library></div>
                    <div class="usb-empty" data-usb-library-empty hidden>
                        <i class="fa-solid fa-box-open"></i>
                        {{ __('No content imported yet.') }}
                    </div>
                </div>
            @endif

        </div>
    </div>
</section>

@push('scripts')
    <link rel="stylesheet" href="{{ asset('assets/css/usb-import.css') }}">
    <script src="{{ asset('assets/js/usb-import.js') }}" defer></script>
@endpush
