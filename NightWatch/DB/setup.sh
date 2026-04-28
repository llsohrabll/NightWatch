#!/bin/bash

sudo mkdir -p /var/www/config
sudo cp db_config.php /var/www/config/

sudo chown root:www-data /var/www/config/db_config.php
sudo chmod 640 /var/www/config/db_config.php