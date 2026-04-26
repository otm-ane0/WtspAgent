<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp AI Agent</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #e2e8f0;
            display: grid;
            place-items: center;
        }

        .card {
            width: min(680px, 92vw);
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 20px 40px rgba(2, 6, 23, 0.4);
            backdrop-filter: blur(8px);
        }

        h1 {
            margin: 0 0 10px;
            font-size: clamp(1.4rem, 2.2vw, 2.1rem);
        }

        p {
            margin: 0.4rem 0;
            opacity: 0.92;
            line-height: 1.5;
        }

        .status {
            margin-top: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #86efac;
            font-weight: 600;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #22c55e;
            box-shadow: 0 0 12px #22c55e;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>WhatsApp AI Agent</h1>
        <p>The Laravel service is up and running.</p>
        <p>Use <strong>/health</strong> or <strong>/api/health</strong> to check API status.</p>
        <div class="status">
            <span class="dot" aria-hidden="true"></span>
            Service healthy
        </div>
    </main>
</body>
</html>
