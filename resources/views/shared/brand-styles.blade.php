<style>
    :root {
        --brand-blue: #31539A;
        --brand-blue-hover: #27437D;
        --brand-orange: #ED7137;
        --brand-background: #F6F8FB;
        --brand-text: #172C43;
    }
    .company-logo { display: block; width: 100%; height: auto; object-fit: contain; }
    .glos-admin .brand { display: block; padding: 8px; border-radius: 6px; background: #fff; border-bottom: 3px solid var(--brand-orange); }
    .glos-auth { margin: 0; background: var(--brand-background); color: var(--brand-text); font: 16px/1.6 'Instrument Sans', system-ui, sans-serif; }
    .glos-auth * { box-sizing: border-box; }
    .glos-auth main { width: min(100%, 560px); margin: auto; padding: 32px 20px; }
    .glos-auth .auth-brand { display: block; width: min(100%, 360px); margin: 0 auto 28px; padding: 10px; border-bottom: 3px solid var(--brand-orange); border-radius: 6px; background: #fff; }
    .glos-auth .auth-brand:focus-visible { outline: 3px solid var(--brand-blue); outline-offset: 4px; }
    .glos-auth h1, .glos-auth h2 { color: var(--brand-blue); }
    .glos-auth .auth-form p { margin-bottom: 16px; }
    .glos-auth .auth-form small { display: block; margin-top: 6px; }
    @media (max-width: 560px) {
        .glos-auth main { padding: 24px 16px; }
        .glos-auth .auth-form { padding: 20px; }
    }
</style>
