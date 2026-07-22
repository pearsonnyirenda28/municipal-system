<?php
/**
 * includes/error_page.php
 * Improvement 18: Global Error Handling.
 * Deliberately self-contained - no dependency on includes/header.php
 * (which needs a working DB connection and session), since this page's
 * whole job is to render safely even when something upstream broke.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Something went wrong - Beitbridge Municipality</title>
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #F4F6F9; color: #1e222a; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(22,34,62,0.1); padding: 40px; max-width: 440px; text-align: center; }
    h1 { font-size: 1.3rem; margin: 0 0 10px; color: #16223E; }
    p { font-size: 0.95rem; color: #5B6472; line-height: 1.5; }
    a.btn { display: inline-block; margin-top: 16px; background: #2C7873; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 0.9rem; }
</style>
</head>
<body>
    <div class="box">
        <h1>Something went wrong</h1>
        <p>An unexpected error occurred while processing your request. It has been logged, and an Administrator can review it in the system.</p>
        <p>Nothing you entered was lost from the database if you were partway through saving something - please go back and try again.</p>
        <a class="btn" href="/municipal-system/dashboard.php">Back to Dashboard</a>
    </div>
</body>
</html>
