<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Certificate Verification | Maramo Beauty Institute</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --gold: #c59b27;
      --gold-deep: #856404;
      --dark: #1a1310;
      --bg: #fcfaf7;
    }
    * { box-sizing: border-box; margin:0; padding:0; }
    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: var(--dark);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .verify-card {
      background: #ffffff;
      border-radius: 20px;
      box-shadow: 0 10px 40px rgba(26,19,16,0.08);
      border: 1px solid rgba(197,155,39,0.25);
      max-width: 580px;
      width: 100%;
      overflow: hidden;
      text-align: center;
    }
    .verify-header {
      background: var(--dark);
      color: #fff;
      padding: 2rem 1.5rem 1.75rem;
      border-bottom: 3px solid var(--gold);
    }
    .verify-brand {
      font-family: 'Cormorant Garamond', serif;
      font-size: 1.75rem;
      font-weight: 700;
      letter-spacing: 1px;
      color: #fff;
    }
    .verify-sub {
      font-size: 0.75rem;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: var(--gold);
      margin-top: 4px;
    }
    .badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 1.25rem;
      padding: 0.5rem 1.25rem;
      border-radius: 999px;
      font-weight: 600;
      font-size: 0.85rem;
      background: #e8f5e9;
      color: #2e7d32;
      border: 1px solid #c8e6c9;
    }
    .verify-body {
      padding: 2.25rem 2rem 2rem;
    }
    .graduate-name {
      font-family: 'Cormorant Garamond', serif;
      font-size: 2.1rem;
      font-weight: 700;
      color: var(--dark);
      margin: 0.5rem 0 1rem;
    }
    .details-table {
      width: 100%;
      margin: 1.5rem 0;
      border-collapse: collapse;
      text-align: left;
    }
    .details-table th, .details-table td {
      padding: 0.75rem 0.5rem;
      border-bottom: 1px solid #f0e9df;
      font-size: 0.92rem;
    }
    .details-table th {
      color: #777;
      font-weight: 500;
      width: 40%;
    }
    .details-table td {
      font-weight: 600;
      color: var(--dark);
    }
    .serial-box {
      background: #faf6ef;
      border: 1px dashed var(--gold);
      padding: 0.75rem;
      border-radius: 10px;
      font-family: monospace;
      font-size: 0.95rem;
      color: var(--gold-deep);
      font-weight: 700;
      margin-top: 1rem;
    }
    .footer-note {
      font-size: 0.78rem;
      color: #888;
      margin-top: 1.5rem;
    }
  </style>
</head>
<body>
  <div class="verify-card">
    <div class="verify-header">
      <div class="verify-brand">MARAMO BEAUTY INSTITUTE</div>
      <div class="verify-sub">Accra, Ghana &bull; Official Credential Registry</div>
      <div class="badge-pill">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        Officially Verified & Authentic
      </div>
    </div>
    <div class="verify-body">
      <p style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1.5px; color: #888;">Certificate Awarded To</p>
      <h2 class="graduate-name">{{ $studentName }}</h2>

      <table class="details-table">
        <tr>
          <th>Program / Course</th>
          <td>{{ $courseName }}</td>
        </tr>
        <tr>
          <th>Student ID</th>
          <td>{{ $admissionNo }}</td>
        </tr>
        <tr>
          <th>Date Conferred</th>
          <td>{{ $issuedAt }}</td>
        </tr>
        <tr>
          <th>Issuing Campus</th>
          <td>Accra Academy Campus, Ghana</td>
        </tr>
      </table>

      <div class="serial-box">
        Certificate Serial: {{ $certNumber }}
      </div>

      <p class="footer-note">&copy; {{ date('Y') }} Maramo Beauty Institute. Boundary Road, Accra, Ghana.</p>
    </div>
  </div>
</body>
</html>
