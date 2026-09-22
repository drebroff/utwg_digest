# 4chan /twg/ & /utwg/ Daily Digest Bot

Автоматический бот на **PHP 8.2+**, который ежедневно собирает обсуждения из двух главных тредов айтишников на имиджборде 4chan (`/g/`):
- **/twg/** — *Tech Workers General* (трудоустроенные айтишники, галеры, лейоффы, оверэмплоймент).
- **/utwg/** — *Unemployable Tech Workers General* (безработные вкатуны и сеньоры, вой об отсутствии работы, сычевание, литкод).

Бот выгружает посты за вчерашний день, восстанавливает контекст цепочек (если анон вчера отвечал на реплику позавчера), генерирует единую сатирическую выжимку в сочном сленге имиджборд через LLM API (Groq Llama 3.3 70B, xAI Grok или Google Gemini), публикует дайджест и все прикрепленные картинки альбомами в Telegram-канал, а также отправляет Email-оповещения через **Brevo** в случае сбоев.

---

## ⚡ Особенности реализации

- **Умное дерево контекста**: Если анон вчера в `00:15` ответил на пост от `23:45` позавчера, бот находит родительский пост и передает его в модель с пометкой `[Контекст]`, чтобы суть спора не терялась.
- **Прямая генерация на русском в имиджборд-стиле**: Модель пишет живым языком (аноны, вкатуны, сеньоры, галеры, гребцы, кукож, оферы, сычевание, лейоффы) без занудного академизма.
- **Строгий лимит Telegram**: Выжимка укладывается в одно сообщение Telegram (до 3500 символов), сохраняя ленту канала компактной.
- **Пакетная публикация картинок**: Картинки за вчерашний день пакуются в альбомы до 10 штук (`sendMediaGroup`) с паузами во избежание флуд-контроля.
- **Email-алерты через Brevo**: Оповещения на почту по SMTP, если один из тредов не найден в каталоге, если за день было 0 постов, либо при критической ошибке.
- **Развертывание на Oracle Cloud Always Free VPS**: Готовый скрипт развертывания одной командой с настройкой `crontab` на ежедневный запуск.

---

## 📋 Структура проекта

```text
utwg_digest/
├── deploy/
│   └── oracle_setup.sh       # Скрипт авторазвертывания на Oracle Cloud VPS
├── src/
│   ├── Ai/
│   │   ├── AiClientInterface.php
│   │   ├── AiFactory.php        # Фабрика выбора Groq / Grok / Gemini
│   │   ├── GeminiClient.php     # Клиент Google AI Studio (Gemini 2.0 Flash)
│   │   ├── GrokClient.php       # Клиент xAI Grok
│   │   ├── GroqClient.php       # Клиент Groq Cloud (Llama 3.3 70B)
│   │   └── PromptBuilder.php    # Сборка структурированного промпта в сленге
│   ├── Chan/
│   │   ├── CatalogParser.php    # Поиск /twg/ и /utwg/ в catalog.json
│   │   └── ThreadParser.php     # Фильтрация постов за вчера, контекст цитат, медиа
│   ├── Notifier/
│   │   └── EmailAlert.php       # Отправка SMTP алертов через Brevo
│   ├── Telegram/
│   │   └── TelegramPublisher.php# Публикация дайджеста и медиагрупп
│   └── Config.php               # Загрузка конфигурации окружения
├── .env.example                 # Шаблон переменных окружения
├── composer.json
└── run.php                      # Точка входа для CLI и планировщика
```

---

## 🔑 Получение API ключей

### 1. Бесплатный AI API
- **Groq (Рекомендуется)**:
  1. Зарегистрируйтесь на [console.groq.com](https://console.groq.com/).
  2. Перейдите в **API Keys** и создайте новый ключ.
  3. Используется быстрая модель `llama-3.3-70b-versatile` (ответ за 1-2 секунды).
- **xAI Grok**:
  1. Получите ключ на [console.x.ai](https://console.x.ai/).
  2. Модель `grok-beta` или `grok-2-latest`.

### 2. Telegram Bot & Канал
1. Создайте бота через [@BotFather](https://t.me/BotFather) в Telegram и сохраните токен (`TELEGRAM_BOT_TOKEN`).
2. Создайте канал и добавьте созданного бота в **администраторы канала** с правом публикации сообщений.
3. Укажите `TELEGRAM_CHAT_ID` (например, `@my_digest_channel`).

### 3. Email-алерты через Brevo
1. Зарегистрируйтесь на [app.brevo.com](https://app.brevo.com/) (бесплатно 300 писем/день).
2. Перейдите в **Settings** -> **Senders, Domains & Dedicated IPs** -> **SMTP** и сгенерируйте ключ.
3. Укажите в `.env`:
   ```env
   ALERT_EMAIL_TO=your_email@gmail.com
   ALERT_EMAIL_FROM=alerts@your-domain-or-brevo.com
   SMTP_DSN=smtp://your_brevo_account@example.com:xsmtpsib-your_brevo_master_key@smtp-relay.brevo.com:587
   ```

---

## 🚀 Развертывание на Oracle Cloud Always Free VPS

1. Подключитесь к VPS по SSH:
   ```bash
   ssh ubuntu@<IP_АДРЕС_ORACLE_VPS>
   ```
2. Склонируйте репозиторий:
   ```bash
   sudo git clone https://github.com/ВАШ_АККАУНТ/utwg_digest.git /opt/utwg_digest
   sudo chown -R ubuntu:ubuntu /opt/utwg_digest
   cd /opt/utwg_digest
   ```
3. Запустите скрипт автоматической настройки:
   ```bash
   ./deploy/oracle_setup.sh
   ```
4. Создайте `.env` и внесите ваши ключи:
   ```bash
   cp .env.example .env
   nano .env
   ```
5. Протестируйте запуск:
   ```bash
   php run.php --dry-run
   ```

Скрипт настроил `crontab` на запуск каждый день в 04:00 UTC (07:00 по МСК) с выводом в `/var/log/chan_digest.log`.
