<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Projects') - {{ config('app.name', 'ReportFlow AI') }}</title>
        @yield('head')

        {{-- Minimal, dependency-free styling. Keeps the UI simple without requiring a Vite/Tailwind build. --}}
        <style>
            :root { color-scheme: light dark; }
            * { box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                margin: 0;
                background: #f7f7f8;
                color: #1b1b18;
            }
            .container { max-width: 960px; margin: 2rem auto; padding: 0 1.5rem; }
            .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
            h1 { font-size: 1.5rem; margin: 0; }
            .btn {
                display: inline-block;
                padding: 0.5rem 1rem;
                background: #1b1b18;
                color: #fff;
                border-radius: 4px;
                text-decoration: none;
                font-size: 0.875rem;
                border: none;
                cursor: pointer;
            }
            .btn:hover { background: #333; }
            .btn-secondary { background: #e5e5e5; color: #1b1b18; }
            table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); }
            th, td { text-align: left; padding: 0.75rem 1rem; border-bottom: 1px solid #eee; font-size: 0.875rem; }
            th { background: #fafafa; font-weight: 600; }
            .badge { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; }
            .badge-active { background: #dcfce7; color: #166534; }
            .badge-archived { background: #e5e5e5; color: #52525b; }
            .badge-pending { background: #fef3c7; color: #92400e; }
            .badge-processing { background: #dbeafe; color: #1e40af; }
            .badge-awaiting-mapping-confirmation { background: #ede9fe; color: #5b21b6; }
            .badge-completed { background: #dcfce7; color: #166534; }
            .badge-failed { background: #fee2e2; color: #991b1b; }
            .badge-high { background: #dcfce7; color: #166534; }
            .badge-medium { background: #fef3c7; color: #92400e; }
            .badge-low { background: #e5e5e5; color: #52525b; }
            .badge-insufficient_data { background: #f3f4f6; color: #6b7280; }
            .alert-error { background: #fee2e2; color: #991b1b; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
            .card { background: #fff; border-radius: 6px; padding: 1rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); }
            .card h2 { font-size: 1.1rem; margin: 0 0 0.75rem; }
            .card h3 { font-size: 0.95rem; margin: 1rem 0 0.5rem; }
            .metadata dt { font-weight: 600; font-size: 0.8rem; color: #6b7280; }
            .metadata dd { margin: 0.2rem 0 0.8rem; white-space: pre-wrap; }
            .alert-success { background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
            .errors { background: #fee2e2; color: #991b1b; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
            .errors ul { margin: 0; padding-left: 1.25rem; }
            form .field { margin-bottom: 1rem; }
            label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 600; }
            input[type="text"], textarea, select {
                width: 100%;
                padding: 0.5rem;
                border: 1px solid #d4d4d8;
                border-radius: 4px;
                font-size: 0.875rem;
            }
            .pagination { margin-top: 1rem; }
            .hint { color: #6b7280; font-size: 0.8rem; margin-top: 0.25rem; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="page-header">
                <h1>@yield('title', 'Projects')</h1>
                <div>@yield('actions')</div>
            </div>

            @if (session('success'))
                <div class="alert-success">{{ session('success') }}</div>
            @endif

            @yield('content')
        </div>
    </body>
</html>
