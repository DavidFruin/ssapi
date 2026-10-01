<?php
// Scheduled-maintenance page. Dormant by design -- named index.maintenance.php
// so it does nothing until renamed to index.php. .htaccess has a rule near
// the top that, whenever a file literally named index.php exists in the
// docroot, rewrites every request (pages, api.php, media.php, everything)
// to it instead of the normal routing. To take the site down for planned
// work: `mv index.maintenance.php index.php` on the server. To bring it
// back: rename it back (or delete index.php -- normal routing resumes the
// moment it's gone, no server restart or cache clear needed).
http_response_code(503);
header('Retry-After: 3600');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scheduled Maintenance - Simple Social</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100svh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    color: #1f2937;
    font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 1.5rem;
  }
  @media (prefers-color-scheme: dark) {
    body { background: #1f2937; color: #f3f4f6; }
  }
  .card { max-width: 420px; text-align: center; }
  h1 { font-size: 1.5rem; margin: 0 0 0.75rem; }
  p { margin: 0; opacity: 0.8; }
</style>
</head>
<body>
  <div class="card">
    <h1>Scheduled Maintenance</h1>
    <p>Simple Social is briefly offline for planned maintenance. Please check back shortly.</p>
  </div>
</body>
</html>
