# Site Bridge

WordPress-плагин для безопасного программного доступа к сайту через HMAC-подписанный REST API.

## Зачем

Стандартный auth через Application Password легко ломается всеми WAF/firewall/security-плагинами,
которые мониторят `wp_signon` и `Authorization: Basic` (NinjaFirewall, Wordfence, Really Simple
Security, Imunify360 и т.д.). Site Bridge использует собственный заголовок `X-SB-Signature` с
HMAC-подписью, который WAF не воспринимает как login-попытку.

## Возможности (v1.0.0)

| Группа | Эндпоинты |
|---|---|
| **System** | `GET /ping`, `GET /info`, `GET /audit-log`, `GET /error-log` |
| **Pages** | `GET /pages`, `GET /pages/{id}`, `PATCH /pages/{id}` (с auto-backup) |
| **Backups** | `POST /pages/{id}/backup`, `GET /pages/{id}/backups`, `POST /pages/{id}/restore/{backup_id}` |
| **Plugins** | `GET /plugins`, `POST /plugins/upload`, `POST /plugins/{slug}/activate\|deactivate`, `DELETE /plugins/{slug}` |
| **Files** | `GET /files`, `PUT /files`, `DELETE /files`, `GET /files/list` (whitelist путей) |
| **Cache** | `POST /cache/purge` (Rocket / LiteSpeed / Seraphinite / WP Object Cache) |
| **Forms** | `GET /forms`, `GET /forms/submissions`, `GET /forms/submissions/{id}` (читает таблицы плагина `custom-forms-sms`) |

## Безопасность

- **HMAC-SHA256** подпись каждого запроса (timestamp + method + path + sha256(body))
- **Timestamp tolerance** ±5 минут (защита от replay)
- **IP whitelist** (опционально)
- **Rate limit**: 5 неудачных auth подряд → бан IP на 1 час
- **Audit log** — все запросы (успешные и неуспешные)
- **Email alerts** на burst неудачных auth и опасные операции
- **Killswitch** через `define('SITE_BRIDGE_DISABLED', true);` в wp-config
- **Whitelist путей** для `/files` (нельзя писать в wp-admin/, wp-includes/, .htaccess, wp-config.php, и в собственный каталог)
- **`hash_equals`** для timing-safe сравнения подписи
- **Graceful secret rotation** через `SITE_BRIDGE_SECRET_PREVIOUS`

## Установка

### 1. Сгенерировать секрет

```bash
python3 -c "import secrets; print(secrets.token_urlsafe(48))"
```

Получится строка ~64 символа.

### 2. Добавить в `wp-config.php`

```php
// ОБЯЗАТЕЛЬНО
define( 'SITE_BRIDGE_SECRET', '<вставить-сгенерированный-секрет>' );

// РЕКОМЕНДУЕТСЯ
define( 'SITE_BRIDGE_ALLOWED_IPS', '150.228.35.171' );  // CSV или CIDR; пусто = любой IP

// ОПЦИОНАЛЬНО
define( 'SITE_BRIDGE_SECRET_PREVIOUS', '' );             // для безостановочной ротации
define( 'SITE_BRIDGE_TIMESTAMP_TOLERANCE', 300 );        // секунд
define( 'SITE_BRIDGE_ALERT_EMAIL', '' );                 // пусто = admin_email сайта
define( 'SITE_BRIDGE_LOG_LEVEL', 'debug' );              // info | debug; в разработке — debug
define( 'SITE_BRIDGE_DISABLED', false );                 // killswitch
```

### 3. Установить плагин

WP-admin → Плагины → Загрузить → выбрать `site-bridge-1.0.0.zip` → Установить → Активировать.

При активации создаются таблицы `{prefix}sb_audit` и `{prefix}sb_page_backups`.

### 4. Сохранить секрет в `wordpress-sites.json`

Расширить схему — добавить поле `site_bridge_secret` для каждого сайта:

```json
{
  "sites": {
    "alumservis": {
      "url": "https://alumservis.com.ua",
      "username": "...",
      "app_password": "...",
      "site_bridge_secret": "<тот же секрет, что в wp-config>"
    }
  }
}
```

### 5. Проверить через Python-клиент

```bash
python3 sb_client.py alumservis ping
```

Должно вернуться:

```json
{
  "status": "ok",
  "plugin": "site-bridge",
  "version": "1.0.0",
  "wp_version": "6.9.4",
  ...
}
```

## Ротация секрета

При компрометации:

1. Сгенерировать новый секрет.
2. Положить старый секрет в `SITE_BRIDGE_SECRET_PREVIOUS`, новый — в `SITE_BRIDGE_SECRET`.
3. Обновить `wordpress-sites.json` на свежий секрет.
4. После 24 часов (или сразу) — удалить `SITE_BRIDGE_SECRET_PREVIOUS`.

## Использование из Python

```python
from sb_client import SiteBridge

sb = SiteBridge("alumservis")

# Ping
print(sb.ping())

# Прочитать страницу с breakdance_data
page = sb.get_page(1580)
breakdance = page["meta"]["breakdance_data"]

# Изменить
new_breakdance = breakdance.replace("старый текст", "новый текст")
sb.update_page(1580, meta={"breakdance_data": new_breakdance})

# Очистить кэш
sb.purge_cache(targets=["rocket", "litespeed"], url="https://alumservis.com.ua/vorota/")

# Деплой обновлённого плагина custom-forms-sms на этот сайт
sb.upload_plugin("/path/to/custom-forms-sms-2.6.5.zip", activate=True, overwrite=True)

# Чтение журналов
print(sb.audit(limit=20, auth_status="ok"))
print(sb.error_log(lines=200))
```

## Подпись запроса (для других клиентов)

```
Message = TIMESTAMP + "\n" + METHOD + "\n" + PATH + "\n" + sha256_hex(BODY)
Signature = HMAC-SHA256(secret, message)  // hex, lowercase

Headers:
  X-SB-Timestamp: <unix-seconds>
  X-SB-Signature: <hex>
```

- **PATH** — REST route без префикса `/wp-json`, например `/sb/v1/pages/1580`.
- **BODY** — сырые байты тела запроса (для GET — пустая строка).
- **Query string в подпись НЕ включаем** (v1) — кладите параметры в body, если требуется их подписать.

## Что НЕЛЬЗЯ делать через плагин

Чтобы избежать самосаботажа, заблокировано:
- Редактировать собственный каталог `wp-content/plugins/site-bridge/*` через `/files`
  (для self-update используется `/plugins/upload` с `overwrite=true`).
- Деактивировать или удалять плагин Site Bridge через `/plugins/{slug}/...`.
- Писать в `wp-config.php`, `.htaccess`, `wp-admin/`, `wp-includes/`, dotfiles в корне.

## Лицензия

GPLv2 or later.
