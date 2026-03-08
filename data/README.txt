This directory is auto-created by the application.
It stores the SQLite database and log files.

IMPORTANT: Keep this directory OUTSIDE your web root if possible.
Or ensure the .htaccess blocks public access to data/*.

To move outside web root, edit config/config.php:
  define('DATA_PATH', '/var/private/solana-agent-data');
