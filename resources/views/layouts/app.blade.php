<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Projects') - {{ config('app.name', 'ReportFlow AI') }}</title>
        @yield('head')

        <style>
            :root {
                color-scheme: light;
                --rf-bg: #f6f7f9; --rf-surface: #fff; --rf-border: #e4e4e7;
                --rf-text: #1b1b18; --rf-text-secondary: #5b6270; --rf-text-muted: #8a8f98;
                --rf-primary: #1b1b18; --rf-primary-contrast: #fff; --rf-accent: #4f46e5; --rf-accent-hover: #4338ca;
                --rf-success-bg: #dcfce7; --rf-success-fg: #166534; --rf-pending-bg: #fef3c7; --rf-pending-fg: #92400e;
                --rf-info-bg: #dbeafe; --rf-info-fg: #1e40af; --rf-waiting-bg: #ede9fe; --rf-waiting-fg: #5b21b6;
                --rf-danger-bg: #fee2e2; --rf-danger-fg: #991b1b; --rf-neutral-bg: #efefef; --rf-neutral-fg: #52525b;
                --rf-priority-high-bg: #ffedd5; --rf-priority-high-fg: #9a3412; --rf-priority-medium-bg: #fef9c3;
                --rf-priority-medium-fg: #854d0e; --rf-priority-low-bg: #f3f4f6; --rf-priority-low-fg: #4b5563;
                --rf-advisory-bg: #eef2ff; --rf-advisory-fg: #3730a3; --rf-advisory-border: #c7d2fe;
                --rf-radius-sm: 6px; --rf-radius-md: 10px; --rf-shadow-sm: 0 1px 2px rgba(16,24,40,.06);
                --rf-shadow-md: 0 1px 3px rgba(16,24,40,.10), 0 1px 2px rgba(16,24,40,.06);
                --rf-space-1: 4px; --rf-space-2: 8px; --rf-space-3: 12px; --rf-space-4: 16px;
                --rf-space-5: 24px; --rf-space-6: 32px; --rf-space-7: 48px;
                --rf-text-xs: 12px; --rf-text-sm: 14px; --rf-text-base: 15px; --rf-h3: 16px; --rf-h2: 18px; --rf-h1: 24px;
            }
            * { box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                margin: 0; background: var(--rf-bg); color: var(--rf-text); font-size: var(--rf-text-base); line-height: 1.5;
            }
            a { color: var(--rf-accent); }
            a:hover { color: var(--rf-accent-hover); }
            a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible { outline: 3px solid var(--rf-advisory-border); outline-offset: 2px; }
            .app-nav { background: var(--rf-surface); border-bottom: 1px solid var(--rf-border); }
            .app-nav-inner { max-width: 960px; margin: 0 auto; padding: var(--rf-space-3) var(--rf-space-5); display: flex; align-items: center; justify-content: space-between; gap: var(--rf-space-4); }
            .brand { color: var(--rf-text); font-weight: 700; text-decoration: none; }
            .nav-link { color: var(--rf-text-secondary); font-weight: 600; text-decoration: none; padding: var(--rf-space-2); }
            .nav-link[aria-current="page"] { color: var(--rf-accent); }
            .container { max-width: 960px; margin: var(--rf-space-6) auto; padding: 0 var(--rf-space-5); }
            .page-header { display: flex; align-items: center; justify-content: space-between; gap: var(--rf-space-4); margin-bottom: var(--rf-space-5); }
            .page-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--rf-space-2); }
            h1 { font-size: var(--rf-h1); margin: 0; overflow-wrap: anywhere; }
            h2 { font-size: var(--rf-h2); } h3 { font-size: var(--rf-h3); }
            .btn {
                display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: var(--rf-space-2) var(--rf-space-4);
                background: var(--rf-primary); color: var(--rf-primary-contrast); border-radius: var(--rf-radius-sm); text-decoration: none;
                font-size: var(--rf-text-sm); border: 1px solid transparent; cursor: pointer;
            }
            .btn:hover { background: #333; color: var(--rf-primary-contrast); }
            .btn-secondary { background: var(--rf-neutral-bg); color: var(--rf-text); border-color: var(--rf-border); }
            .btn-link { display: inline-flex; align-items: center; min-height: 40px; padding: var(--rf-space-2); color: var(--rf-accent); font-weight: 600; text-decoration: none; }
            .table-scroll { width: 100%; overflow-x: auto; margin-bottom: var(--rf-space-4); border-radius: var(--rf-radius-md); box-shadow: var(--rf-shadow-md); }
            table { width: 100%; border-collapse: collapse; background: var(--rf-surface); }
            .table-scroll table { min-width: 640px; }
            th, td { text-align: left; padding: var(--rf-space-3) var(--rf-space-4); border-bottom: 1px solid var(--rf-border); font-size: var(--rf-text-sm); overflow-wrap: normal; word-break: normal; }
            th { background: #fafafa; font-weight: 600; white-space: nowrap; }
            tbody tr:nth-child(even) { background: #fafafa; }
            .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: var(--rf-text-xs); font-weight: 600; white-space: nowrap; }
            .badge-active, .badge-completed { background: var(--rf-success-bg); color: var(--rf-success-fg); }
            .badge-archived, .badge-insufficient_data { background: var(--rf-neutral-bg); color: var(--rf-neutral-fg); }
            .badge-pending { background: var(--rf-pending-bg); color: var(--rf-pending-fg); }
            .badge-processing { background: var(--rf-info-bg); color: var(--rf-info-fg); }
            .badge-awaiting-mapping-confirmation { background: var(--rf-waiting-bg); color: var(--rf-waiting-fg); }
            .badge-failed { background: var(--rf-danger-bg); color: var(--rf-danger-fg); }
            .badge-high { background: var(--rf-priority-high-bg); color: var(--rf-priority-high-fg); }
            .badge-medium { background: var(--rf-priority-medium-bg); color: var(--rf-priority-medium-fg); }
            .badge-low { background: var(--rf-priority-low-bg); color: var(--rf-priority-low-fg); }
            .badge-advisory { background: var(--rf-advisory-bg); color: var(--rf-advisory-fg); border: 1px solid var(--rf-advisory-border); }
            .alert-error, .errors { background: var(--rf-danger-bg); color: var(--rf-danger-fg); padding: var(--rf-space-3) var(--rf-space-4); border-radius: var(--rf-radius-sm); margin-bottom: var(--rf-space-4); }
            .alert-success { background: var(--rf-success-bg); color: var(--rf-success-fg); padding: var(--rf-space-3) var(--rf-space-4); border-radius: var(--rf-radius-sm); margin-bottom: var(--rf-space-4); }
            .card { background: var(--rf-surface); border: 1px solid var(--rf-border); border-radius: var(--rf-radius-md); padding: var(--rf-space-4); margin-bottom: var(--rf-space-4); box-shadow: var(--rf-shadow-sm); }
            .card h2 { margin: 0 0 var(--rf-space-3); } .card h3 { margin: var(--rf-space-4) 0 var(--rf-space-2); }
            .metadata { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--rf-space-2) var(--rf-space-4); }
            .metadata dt { font-weight: 600; font-size: var(--rf-text-xs); color: var(--rf-text-muted); }
            .metadata dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
            .errors ul { margin: 0; padding-left: 1.25rem; }
            form .field { margin-bottom: var(--rf-space-4); }
            label { display: block; margin-bottom: var(--rf-space-1); font-size: var(--rf-text-sm); font-weight: 600; }
            input[type="text"], textarea, select {
                width: 100%; min-height: 40px; padding: var(--rf-space-2); border: 1px solid var(--rf-border); border-radius: var(--rf-radius-sm); font-size: var(--rf-text-sm); background: var(--rf-surface); color: var(--rf-text);
            }
            .pagination { margin-top: var(--rf-space-4); }
            .pagination-list { display: flex; flex-wrap: wrap; align-items: center; gap: var(--rf-space-2); margin: 0; padding: 0; list-style: none; }
            .pagination-link { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; min-height: 40px; padding: var(--rf-space-2) var(--rf-space-3); border: 1px solid var(--rf-border); border-radius: var(--rf-radius-sm); background: var(--rf-surface); color: var(--rf-accent); text-decoration: none; }
            .pagination-link.is-current { background: var(--rf-accent); color: var(--rf-primary-contrast); border-color: var(--rf-accent); font-weight: 700; }
            .pagination-link.is-disabled { color: var(--rf-text-muted); background: var(--rf-neutral-bg); cursor: not-allowed; }
            .hint { color: var(--rf-text-secondary); font-size: var(--rf-text-xs); margin-top: var(--rf-space-1); }
            .breadcrumb { display: flex; flex-wrap: wrap; gap: var(--rf-space-2); margin: 0 0 var(--rf-space-4); padding: 0; list-style: none; font-size: var(--rf-text-sm); color: var(--rf-text-secondary); }
            .breadcrumb a { text-decoration: none; } .breadcrumb li + li::before { content: '›'; margin-right: var(--rf-space-2); color: var(--rf-text-muted); }
            .empty-state { text-align: center; padding: var(--rf-space-6); } .empty-state svg { width: 32px; color: var(--rf-text-muted); }
            .required-label { color: var(--rf-danger-fg); font-size: var(--rf-text-xs); font-weight: 700; }
            .report-content { max-width: 100%; overflow-x: auto; }
            .html-report { font-size: 16px; line-height: 1.6; } .html-report > section, .html-report > header { background: var(--rf-surface); border: 1px solid var(--rf-border); border-radius: var(--rf-radius-md); padding: var(--rf-space-5); margin-bottom: var(--rf-space-4); break-inside: avoid; }
            .html-report h2 { font-size: 20px; } .html-report h3 { font-size: 17px; }
            .html-report p, .html-report li { overflow-wrap: anywhere; }
            @media (max-width: 640px) {
                .container { margin: var(--rf-space-5) auto; padding: 0 var(--rf-space-4); }
                .page-header { flex-direction: column; align-items: flex-start; gap: var(--rf-space-3); }
                .page-actions { width: 100%; } .page-actions .btn, .page-actions .btn-link { max-width: 100%; }
                .app-nav-inner { padding: var(--rf-space-3) var(--rf-space-4); }
            }
            @media print {
                .app-nav, .page-actions, .breadcrumb, .alert-success { display: none !important; }
                body { background: #fff; } .container { max-width: none; margin: 0; padding: 0; }
                .report-content { overflow: visible; }
                .html-report > section, .html-report > header { box-shadow: none; border: 0; break-inside: avoid; }
                .table-scroll { overflow: visible; box-shadow: none; } .table-scroll table { min-width: 0; }
            }
        </style>
    </head>
    <body>
        <nav class="app-nav" aria-label="Primary navigation">
            <div class="app-nav-inner">
                <a href="{{ route('projects.index') }}" class="brand">ReportFlow AI</a>
                <a href="{{ route('projects.index') }}" class="nav-link" @if (request()->routeIs('projects.index')) aria-current="page" @endif>Projects</a>
            </div>
        </nav>
        <div class="container">
            <div class="page-header">
                <h1>@yield('title', 'Projects')</h1>
                <div class="page-actions">@yield('actions')</div>
            </div>

            @if (session('success'))
                <div class="alert-success" role="status">{{ session('success') }}</div>
            @endif

            @yield('content')
        </div>
    </body>
</html>
