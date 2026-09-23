#!/usr/bin/env bash
# ==============================================================================
# Скрипт автоматической настройки Oracle Cloud Always Free VPS (Ubuntu / Debian)
# для 4chan Daily Digest Bot
# ==============================================================================

set -e

echo "🚀 Начинаем настройку сервера для 4chan Digest Bot..."

# 1. Настройка системного часового пояса в UTC (для строгого запуска по UTC в cron)
echo "🕒 Настройка часового пояса сервера в UTC..."
sudo timedatectl set-timezone UTC

# 2. Обновление пакетов системы
echo "📦 Обновление списка пакетов..."
sudo apt-get update -y
sudo apt-get upgrade -y
sudo apt-get install -y software-properties-common curl git unzip cron
sudo systemctl enable --now cron

# 3. Добавление репозитория Ondřej Surý для актуального PHP
if ! grep -q "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null; then
    echo "📦 Добавление PPA для PHP 8.3..."
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update -y
fi

# 4. Установка PHP 8.3 и расширений
echo "🐘 Установка PHP 8.3 и необходимых расширений..."
sudo apt-get install -y \
    php8.3-cli \
    php8.3-curl \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-gmp

# 5. Установка Composer
if ! command -v composer &> /dev/null; then
    echo "🎼 Установка Composer..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
fi

echo "✅ PHP и Composer установлены:"
php -v
composer -V

# 6. Настройка рабочего каталога
PROJECT_DIR="/opt/utwg_digest"
if [ ! -d "$PROJECT_DIR" ]; then
    echo "📁 Создание каталога проекта: $PROJECT_DIR..."
    sudo mkdir -p "$PROJECT_DIR"
    sudo chown -R $USER:$USER "$PROJECT_DIR"
    echo "ℹ️ Склонируйте сюда репозиторий с GitHub командой:"
    echo "   git clone <URL_ВАШЕГО_РЕПОЗИТОРИЯ> $PROJECT_DIR"
fi

# 7. Настройка ежедневного Cron
# 7.1 Основной дайджест 4chan /twg/ и /utwg/ в 04:00 UTC
CRON_JOB_CHAN="0 4 * * * cd $PROJECT_DIR && /usr/bin/php run.php >> /var/log/chan_digest.log 2>&1"
# 7.2 Бонусный мониторинг чата Drupal (@drupal_rus) в 04:30 UTC (независимый процесс)
CRON_JOB_DRUPAL="30 4 * * * cd $PROJECT_DIR && /usr/bin/php run_drupal.php >> /var/log/drupal_digest.log 2>&1"

EXISTING_CRON=$(crontab -l 2>/dev/null || true)
NEW_CRON="$EXISTING_CRON"

if ! echo "$NEW_CRON" | grep -q "run.php"; then
    echo "⏰ Добавление задания 4chan в crontab (запуск в 04:00 UTC)..."
    NEW_CRON=$(printf "%s\n%s" "$NEW_CRON" "$CRON_JOB_CHAN")
fi

if ! echo "$NEW_CRON" | grep -q "run_drupal.php"; then
    echo "⏰ Добавление задания Drupal в crontab (запуск в 04:30 UTC)..."
    NEW_CRON=$(printf "%s\n%s" "$NEW_CRON" "$CRON_JOB_DRUPAL")
fi

echo "$NEW_CRON" | sed '/^$/d' | crontab -
echo "✅ Задания в crontab настроены:"
crontab -l | grep -E "run\.php|run_drupal\.php" || true

# 8. Настройка файлов логов
sudo touch /var/log/chan_digest.log /var/log/drupal_digest.log
sudo chown $USER:$USER /var/log/chan_digest.log /var/log/drupal_digest.log

echo ""
echo "🎉 Сервер Oracle Cloud успешно подготовлен!"
echo "Следующие шаги:"
echo "1. Перейдите в каталог: cd $PROJECT_DIR"
echo "2. Скопируйте .env.example в .env и заполните ключи: cp .env.example .env && nano .env"
echo "3. Установите зависимости: composer install --no-dev"
echo "4. Авторизуйте сессию Telegram MTProto для Drupal: php run_drupal.php --auth"
echo "5. Протестируйте работу скриптов в режиме dry-run:"
echo "   php run.php --dry-run"
echo "   php run_drupal.php --dry-run"
