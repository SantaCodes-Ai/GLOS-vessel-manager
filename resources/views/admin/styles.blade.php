@include('shared.brand-styles')
{{-- Keep core dashboard styling available even when Vite assets are unavailable or stale. --}}
<style>
    .glos-admin { margin: 0; background: var(--brand-background); color: #172c43; font: 15px/1.6 'Instrument Sans', system-ui, sans-serif; }
    .glos-admin * { box-sizing: border-box; }
    .glos-admin a { color: inherit; text-decoration: none; }
    .glos-admin a:focus-visible, .glos-admin button:focus-visible { outline: 3px solid var(--brand-blue); outline-offset: 4px; }
    .glos-admin .skip-link { position: fixed; top: -100px; left: 16px; z-index: 10; padding: 12px; background: white; }
    .glos-admin .skip-link:focus { top: 12px; }
    .glos-admin .sidebar { position: fixed; inset: 0 auto 0 0; width: 250px; background: var(--brand-blue); color: #fff; padding: 32px 24px; display: flex; flex-direction: column; }
    .glos-admin .brand { display: flex; align-items: center; gap: 12px; color: white; font-size: 18px; font-weight: 700; line-height: 1.35; }

    .glos-admin .sidebar a:focus-visible, .glos-admin .sidebar button:focus-visible { outline-color: #fff; }
    .glos-admin .sidebar nav a[aria-current] { border-inline-start: 3px solid #fff; font-weight: 700; }
    .glos-admin .nav-label { margin: 48px 12px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .16em; color: #fff; }
    .glos-admin nav { display: grid; gap: 8px; }
    .glos-admin nav a { padding: 12px; border-radius: 8px; }
    .glos-admin nav a:hover, .glos-admin nav a[aria-current] { color: white; background: var(--brand-blue-hover); }
    .glos-admin .sidebar-footer { margin-top: auto; padding-top: 36px; font-size: 12px; color: #fff; }
    .glos-admin .workspace { margin-left: 250px; }
    .glos-admin .topbar { min-height: 80px; padding: 16px 40px; background: white; border-bottom: 1px solid #dce5ed; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .glos-admin .workspace-label { font-size: 13px; color: #52677c; }
    .glos-admin .logout-button { min-height: 44px; border: 1px solid #c4d2df; border-radius: 7px; padding: 8px 18px; background: white; color: #172c43; font: inherit; cursor: pointer; }
    .glos-admin .logout-button:hover { background: #edf3f8; }
    .glos-admin main { max-width: 1500px; margin: auto; padding: 40px; }
    .glos-admin .eyebrow { color: var(--brand-blue); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .16em; margin: 0 0 8px; }
    .glos-admin h1 { color: var(--brand-blue); font-size: clamp(26px, 3vw, 36px); font-weight: 700; line-height: 1.25; margin: 0 0 12px; letter-spacing: -.03em; }
    .glos-admin .welcome { margin: 0 0 32px; color: #52677c; overflow-wrap: anywhere; }
    .glos-admin .summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; margin: 0 0 32px; }
    .glos-admin .summary-card { background: white; border: 1px solid #dce5ed; border-radius: 12px; padding: 24px; border-top: 3px solid var(--brand-orange); }
    .glos-admin .summary-card-interactive { position: relative; cursor: pointer; }
    .glos-admin .summary-card-interactive:hover { border-color: var(--brand-blue); background: #f8fafc; }
    .glos-admin .summary-card-link::after { content: ""; position: absolute; inset: 0; border-radius: 12px; }
    .glos-admin .summary-card-link:focus-visible { outline: none; }
    .glos-admin .summary-card-link:focus-visible::after { outline: 3px solid var(--brand-blue); outline-offset: 4px; }
    .glos-admin .summary-card dt { color: #52677c; font-size: 14px; }
    .glos-admin .summary-card dd { margin: 8px 0 0; font-size: 36px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .glos-admin .panel { background: white; border: 1px solid #dce5ed; border-radius: 12px; margin-bottom: 24px; overflow: hidden; scroll-margin-top: 20px; }
    .glos-admin .panel-heading { padding: 22px 24px; border-bottom: 1px solid #e5ecf2; }
    .glos-admin h2 { color: var(--brand-blue); font-size: 18px; font-weight: 700; margin: 0; }
    .glos-admin .panel-heading p { color: #52677c; font-size: 13px; margin: 4px 0 0; }
    .glos-admin .table-scroll { overflow-x: auto; }
    .glos-admin table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
    .glos-admin caption { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    .glos-admin th { background: #f8fafc; color: #52677c; font-size: 12px; font-weight: 600; }
    .glos-admin th, .glos-admin td { padding: 16px 24px; border-bottom: 1px solid #edf2f6; }
    .glos-admin td { overflow-wrap: anywhere; min-width: 130px; }
    .glos-admin tbody tr:last-child td { border-bottom: 0; }
    .glos-admin .empty-state { padding: 40px 24px; text-align: center; color: #52677c; }
    .glos-admin .empty-state strong { display: block; color: #172c43; margin-bottom: 6px; }
    .glos-admin .empty-state p { margin: 0; }
    .glos-admin .page-footer { padding: 0 40px 28px; color: #52677c; font-size: 12px; }
    @media (max-width: 900px) {
        .glos-admin .sidebar { position: static; width: auto; padding: 20px; }
        .glos-admin .brand br { display: none; }
        .glos-admin .brand span:last-child { display: flex; gap: 5px; flex-wrap: wrap; }
        .glos-admin .nav-label, .glos-admin .sidebar-footer { display: none; }
        .glos-admin nav { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px; }
        .glos-admin nav a { font-size: 13px; padding: 10px; }
        .glos-admin .workspace { margin-left: 0; }
        .glos-admin .topbar { padding: 16px 20px; }
        .glos-admin main { padding: 28px 20px; }
        .glos-admin .page-footer { padding: 0 20px 24px; }
    }
    @media (max-width: 560px) {
        .glos-admin .summary-grid { grid-template-columns: 1fr; gap: 12px; }
        .glos-admin .summary-card { padding: 18px 24px; }
    }
    .glos-admin .vessel-toolbar, .glos-admin .vessel-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
    .glos-admin .vessel-button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 20px; border: 1px solid var(--brand-blue); border-radius: 7px; background: var(--brand-blue); color: white; font: inherit; font-weight: 600; cursor: pointer; }
    .glos-admin .vessel-button:hover { background: var(--brand-blue-hover); }
    .glos-admin .vessel-link { color: var(--brand-blue); text-decoration: underline; text-underline-offset: 3px; }
    .glos-admin .vessel-form, .glos-admin .vessel-filters { padding: 24px; }
    .glos-admin .vessel-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; margin: 0 0 28px; }
    .glos-admin .vessel-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 16px; }
    .glos-admin .vessel-filters > div { flex: 1; min-width: 180px; }
    .glos-admin label { display: block; font-weight: 600; margin-bottom: 6px; }
    .glos-admin input, .glos-admin select { width: 100%; min-height: 44px; border: 1px solid #8298ac; border-radius: 6px; padding: 10px; background: white; color: #172c43; font: inherit; }
    .glos-admin input:focus-visible, .glos-admin select:focus-visible, .glos-admin .table-scroll:focus-visible { outline: 3px solid var(--brand-blue); outline-offset: 2px; }
    .glos-admin small { color: #52677c; }
    .glos-admin .vessel-error { color: #b91c1c; margin: 6px 0 0; }
    .glos-admin .vessel-error-summary { padding: 20px; margin-bottom: 20px; border: 1px solid #b91c1c; background: #fff1f2; color: #991b1b; border-radius: 8px; }
    .glos-admin .vessel-error-summary ul { list-style: disc; padding-left: 24px; }
    .glos-admin .vessel-success { padding: 16px; border: 1px solid #0f766e; background: #ecfdf5; color: #115e59; border-radius: 8px; margin-bottom: 20px; }
    .glos-admin .vessel-details dt { color: #52677c; }
    .glos-admin .vessel-details dd { margin: 6px 0 0; font-weight: 600; overflow-wrap: anywhere; }
    .glos-admin .vessel-pagination nav { display: flex; margin: 0; }
    @media (max-width: 560px) { .glos-admin .vessel-form-grid { grid-template-columns: 1fr; } }
</style>
<style>
    .glos-admin .certificate-description { width: 100%; min-height: 150px; resize: vertical; border: 1px solid #8298ac; border-radius: 6px; padding: 10px; background: white; color: #172c43; font: inherit; }
    .glos-admin .certificate-description:focus-visible { outline: 3px solid var(--brand-blue); outline-offset: 2px; }
    .glos-admin .certificate-description-text { white-space: pre-wrap; overflow-wrap: anywhere; }
    .glos-admin .expiry-badge { display: inline-block; border-radius: 20px; padding: 4px 10px; font-size: 12px; font-weight: 700; }
    .glos-admin .expiry-permanent { background: #dbeafe; color: #1e40af; }
    .glos-admin .expiry-valid { background: #dcfce7; color: #166534; }
    .glos-admin .expiry-expiring-soon { background: #fef9c3; color: #854d0e; }
    .glos-admin .expiry-critical { background: #ffedd5; color: #9a3412; }
    .glos-admin .expiry-expired { background: #fee2e2; color: #991b1b; }
    .glos-admin .expiry-unknown { background: #e2e8f0; color: #334155; }
    .glos-admin .expiry-meta { display: block; color: #52677c; font-size: 12px; }
    .glos-admin .client-vessel-assignments { border: 1px solid #c4d2df; border-radius: 8px; padding: 20px; margin: 0 0 24px; }
    .glos-admin .client-vessel-assignments legend { padding: 0 8px; font-weight: 700; }
    .glos-admin .client-vessel-options { max-height: 320px; overflow-y: auto; margin-top: 12px; }
    .glos-admin .client-vessel-option { display: flex; gap: 12px; align-items: center; min-height: 44px; font-weight: 400; cursor: pointer; }
    .glos-admin .client-vessel-option input { width: 20px; height: 20px; min-height: 20px; flex-shrink: 0; }
    .glos-admin .delete-user-button { background: #b91c1c; border-color: #b91c1c; color: #fff; }
    .glos-admin .delete-user-button:hover { background: #991b1b; }
    .glos-admin .sidebar { overflow-y: auto; }
    .glos-admin .admin-navigation > summary { padding: 12px; cursor: pointer; font-weight: 700; }
    .glos-admin .nav-group > summary { padding: 12px; cursor: pointer; font-weight: 600; }
    .glos-admin .nav-group a { display: block; margin-left: 12px; font-size: 13px; }
    .glos-admin .nav-logout { background: transparent; color: inherit; border: 1px solid #a5bdcf; border-radius: 7px; min-height: 44px; padding: 10px 14px; font: inherit; cursor: pointer; width: 100%; text-align: left; }
    .glos-admin .nav-group:focus-within, .glos-admin summary:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
    .glos-admin .confirmation-dialog { border: 1px solid #c4d2df; border-radius: 12px; padding: 24px; max-width: min(520px, 92vw); margin: auto; color: #172c43; background: white; }
    .glos-admin .confirmation-dialog::backdrop { background: rgb(15 23 42 / 65%); }
    .glos-admin .confirmation-dialog form { display: inline-block; margin: 16px 8px 0 0; }
    .glos-admin .department-fields { margin-bottom: 24px; }
    @media (max-width: 900px) {
        .glos-admin .admin-navigation { width: 100%; }
        .glos-admin .admin-navigation nav { display: grid; }
    }
</style>
