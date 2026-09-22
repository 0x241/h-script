# Authelia на отдельном VPS-шлюзе

В этой папке находятся полные примеры для схемы, где Nginx и native/systemd
Authelia работают на публичном VPS-шлюзе, а H-Script — на втором VPS и доступен
шлюзу только через Tailscale.

Authelia опциональна и по умолчанию отключена: сервис не входит в
`docker-compose.yml`, Composer-зависимости или runtime H-Script. Без отдельной
установки шлюза продолжают действовать встроенные пароль конфигуратора и
авторизация администратора.

## Файлы

- `nginx/hscript.conf.template` — полный виртуальный хост Nginx для H-Script и
  портала Authelia;
- `nginx/topology.env.example` — публичные имена, адреса, порты и пути к TLS;
- `remote/configuration.yml.example` — минимальная полная конфигурация Authelia
  с SQLite, file backend, SMTP и 2FA для `/_cfg` и `/admin`;
- `remote/users.yml.example` — локальная база пользователей без открытых паролей.

## Подготовка Authelia

Скопируйте примеры на VPS-шлюз и замените все `example.com`, `CHANGE_ME` и
`<ARGON2ID_HASH>`. Каждый секрет должен быть отдельным случайным значением:

```bash
openssl rand -hex 32
openssl rand -hex 32
authelia crypto hash generate argon2
```

Создайте runtime-каталог, проверьте конфигурацию и запустите сервис:

```bash
sudo install -d -o authelia -g authelia -m 0750 /var/lib/authelia
sudo authelia config validate --config /etc/authelia/configuration.yml
sudo systemctl enable --now authelia
curl -I http://127.0.0.1:9091/api/health
```

SMTP-пароль лучше передавать через файл-секрет
`AUTHELIA_NOTIFIER_SMTP_PASSWORD_FILE`, а строку `password` удалить из YAML.

## Подготовка Nginx

Скопируйте `topology.env.example` в защищённый `topology.env`, заполните его и
отрендерите конфигурацию ограниченным списком переменных. Ограничение важно:
оно сохраняет штатные переменные Nginx `$host`, `$request_uri` и другие.

```bash
set -a
. /etc/hscript/topology.env
set +a

envsubst '${HS_PUBLIC_HOST} ${AUTHELIA_PUBLIC_HOST} ${HS_TAILSCALE_IP} ${HS_UPSTREAM_PORT} ${AUTHELIA_LISTEN_IP} ${AUTHELIA_LISTEN_PORT} ${HS_TLS_CERTIFICATE} ${HS_TLS_CERTIFICATE_KEY} ${AUTHELIA_TLS_CERTIFICATE} ${AUTHELIA_TLS_CERTIFICATE_KEY} ${ACME_WEBROOT}' \
  < /etc/hscript/hscript.conf.template \
  | sudo tee /etc/nginx/sites-available/h-script.conf >/dev/null

sudo nginx -t
sudo systemctl reload nginx
```

Для второго корневого домена добавьте отдельный cookie в `session.cookies`,
правило `access_control` и отдельный HTTPS `server` портала с сертификатом этого
домена. Один портал вида `sso.example.com` не может установить cookie для
несвязанного домена.

На VPS H-Script порт приложения должен слушать его Tailscale IP, firewall должен
разрешать этот порт только Tailscale IP шлюза, а `TRUSTED_PROXY_CIDRS` — содержать
точный `/32` адрес шлюза. Authelia не заменяет встроенный пароль конфигуратора и
авторизацию администратора H-Script.

Шаблон Nginx вызывает `auth_request` только для закреплённых
регистрозависимых путей `/_cfg` и `/admin`. Общий `location /` остаётся
публичным и поэтому является обязательным bypass для машинных маршрутов,
которые не могут пройти интерактивную 2FA. Проверьте как минимум:

```text
POST /api/v1/installations/register   # установка регистрируется по Bearer hsi_
POST /api/v1/installations/report     # ежедневный подписанный отчёт
POST /api/v1/installations/domain-verification # challenge/проверка по Bearer hsi_
GET  /api/v1/installations/domain-proof # временный публичный challenge, без секретов
POST /balance/status                  # callback платёжного провайдера
GET  /cron?auto                       # внешний планировщик
```

Не добавляйте `/api/v1`, `/balance/status` или `/cron` в защищённые regex
Nginx/Authelia. Их собственная проверка Bearer token, подписи callback или
внутреннего scheduler-флага остаётся обязательной. После изменения шаблона
проверьте, что `auth_request` встречается только в двух защищённых location.

Если `.env` для H-Script формирует GitLab deployment job, добавьте
`TRUSTED_PROXY_CIDRS` как environment-scoped protected variable со значением
`<GATEWAY_TAILSCALE_IP>/32`. Masked-флаг не нужен: IP-адрес не является секретом.
