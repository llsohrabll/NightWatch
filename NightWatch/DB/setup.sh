#!/bin/bash

sudo mkdir -p /var/www/config
sudo cp db_config.php /var/www/config/

sudo chown root:www-data /var/www/config/db_config.php
sudo chmod 640 /var/www/config/db_config.php





# This script is a simple Apache deployment helper for a Linux server.
# It copies the DB config into /var/www/config and rewrites the default site config.

# Path to the Apache virtual host file that will be replaced.
SITE_CONF="/etc/apache2/sites-available/000-default.conf"

echo "[+] Backing up current config..."
cp $SITE_CONF ${SITE_CONF}.bak

echo "[+] Writing new Apache configuration..."

cat <<EOF > $SITE_CONF
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html

    # Start from "deny all" so the allow-list below is explicit.
    <Location />
        Require all denied
    </Location>

    # Re-open only the routes and assets this app needs.
    <LocationMatch "^/(|index\.html|style\.css|animation\.js|login|register|forgetpassword|panel|js|functions|uploads/photos)">
        Require all granted
    </LocationMatch>

</VirtualHost>
EOF

echo "[+] Enabling required Apache module..."
a2enmod authz_core

echo "[+] Restarting Apache..."
systemctl restart apache2

echo "[+] Done. Public pages, shared assets, API endpoints, and uploaded photos are accessible."
