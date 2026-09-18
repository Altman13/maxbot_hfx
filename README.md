# tgbot_hfx

Бот поддержки пользователей HFX (MaxBot / Telegram) на PHP + Slim Framework.

Обрабатывает обращения, регистрирует пользователей через контакт, создаёт заявки в OkDesk,
работает с QR-кодами плоттеров, поддерживает обратную связь и выгрузку отчётов.

## Стек

- PHP 8.2+
- Slim Framework 4
- PHP-DI (PSR-11)
- Monolog (PSR-3)
- Guzzle HTTP
- MaxBot API (platform-api.max.ru)
- OkDesk API (insitech.okdesk.ru)
- ArmorJack API (api-machine.armorjack.ru)
