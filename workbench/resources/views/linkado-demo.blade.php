<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Linkado Laravel workbench</title>
        @linkadoTracking
    </head>
    <body>
        <main>
            <h1>Linkado Laravel workbench</h1>
            <p>Mode: <code>{{ config('linkado.mode') }}</code></p>
            <p>
                Add the configured referral query parameter to this URL to exercise
                <code>linkado.attribution</code> without a host domain model.
            </p>

            @if (session('status'))
                <p>{{ session('status') }}</p>
            @endif

            <h2>Transactional event</h2>
            <form method="POST" action="{{ route('linkado.demo.customer-created') }}">
                @csrf
                <label>
                    Source key
                    <input name="source_key" value="workbench:customer-created:1" required>
                </label>
                <label>
                    External customer ID
                    <input name="external_customer_id" value="workbench-customer-1" required>
                </label>
                <button type="submit">Record customer event</button>
            </form>

            <h2>SSO</h2>
            <form method="POST" action="{{ route('linkado.sso.launch') }}">
                @csrf
                <button type="submit">Open Linkado</button>
            </form>
        </main>
    </body>
</html>
