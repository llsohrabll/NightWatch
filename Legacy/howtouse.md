# How To Use NightWatch With Apache

Use a normal Apache application path. Do not copy the project directly into `/`.

Recommended path:

```bash
/var/www/NightWatch
```

`setup.sh` configures Apache, permissions, logs, private photo storage, and runtime environment variables. It does not create or import the MySQL database.

## 1. Install Required Packages

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-gd php-mbstring php-curl php-xml rsync openssl
sudo a2enmod rewrite headers ssl
```

## 2. Copy The Project

From the directory that contains your local `NightWatch` folder:

```bash
sudo mkdir -p /var/www/NightWatch
sudo rsync -av ./NightWatch/ /var/www/NightWatch/
sudo chown -R root:root /var/www/NightWatch
```

## 3. Create The Database

Generate a strong database password:

```bash
DB_PASS="$(openssl rand -base64 32)"
echo "$DB_PASS"
```

Import the schema after replacing the placeholder password:

```bash
sudo sed "s/CHANGE_ME_STRONG_PASSWORD/$DB_PASS/g" /var/www/NightWatch/DB/nightwatch_schema.sql > /tmp/nightwatch_schema.sql
mysql -u root -p < /tmp/nightwatch_schema.sql
rm /tmp/nightwatch_schema.sql
```

## 4. Run setup.sh

For local testing on `localhost`:

```bash
cd /var/www/NightWatch

sudo \
  SERVER_NAME='localhost' \
  PROJECT_DIR='/var/www/NightWatch' \
  WEB_ROOT='/var/www/NightWatch/WebApp' \
  CONFIG_DIR='/var/www/config' \
  LOG_DIR='/var/www/NightWatch/logs' \
  NIGHTWATCH_PHOTO_STORAGE_DIR='/var/www/NightWatch/storage/photos' \
  NIGHTWATCH_DB_HOST='localhost' \
  NIGHTWATCH_DB_USER='nightwatch_app' \
  NIGHTWATCH_DB_PASSWORD="$DB_PASS" \
  NIGHTWATCH_DB_NAME='NightWatchDB' \
  ENABLE_HTTPS='0' \
  ./setup.sh
```

## 5. Enable The Site

```bash
sudo a2dissite 000-default.conf
sudo a2ensite nightwatch.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Open:

```text
http://localhost/
```

## Production Notes

For a real domain, use your real hostname:

```bash
SERVER_NAME='yourdomain.com'
```

Production should use HTTPS:

```bash
ENABLE_HTTPS='1'
```

You must also provide valid certificate paths or install a certificate with Certbot before running HTTPS mode.

Set SMTP environment variables before running `setup.sh` if you want registration, verification, password reset, and email-change emails to work:

```bash
SMTP_HOST='smtp.example.com'
SMTP_PORT='587'
SMTP_USERNAME='noreply@example.com'
SMTP_PASSWORD='replace-with-smtp-password'
SMTP_ENCRYPTION='tls'
SMTP_VERIFY_CERT='true'
SMTP_REQUIRE_AUTH='true'
MAIL_FROM_ADDRESS='noreply@example.com'
MAIL_FROM_NAME='NightWatch System'
```

Production endpoints require HTTPS outside localhost.
