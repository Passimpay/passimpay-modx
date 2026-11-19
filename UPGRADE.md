# PassimPay для MODX / miniShop2 — 1.0.3

1. Сохраните резервную копию файлов и базы магазина.
2. Из каталога `modx/` в `passimpay_modx.zip` скопируйте `mspassimpay.class.php` в `core/components/minishop2/custom/payment/`, а `passimpay.php` — в `assets/components/minishop2/payment/`.
3. Для первой установки настройте сниппет `passimpay_select`, chunk `tpl.msGetOrder` и способ оплаты по инструкции `modx/--info.txt` из архива. При обновлении существующие сниппет и chunk заменять не требуется.
4. Проверьте Platform ID, Secret Key, USD rate, режим `mspa_mode` и статус полной оплаты.
5. Notification URL проекта PassimPay: `https://shop.example/assets/components/minishop2/payment/passimpay.php`. Учитывайте подкаталог магазина.
6. Очистите кеш MODX и проверьте создание заказа, оплату и обновление статуса.

API v2 используется автоматически. Нужны PHP cURL, исходящий HTTPS к `https://api.passimpay.io` и действующее хранилище корневых сертификатов.

Сумма заказа переводится в USD делением на настроенный USD rate. В прямом режиме сумма дополнительно делится на USD-курс выбранной криптовалюты. Автоматического обновления USD rate нет.

В прямом режиме заказ завершается после одного достаточного перевода; несколько частичных переводов не суммируются. Если указан Destination Tag, покупатель должен использовать его вместе с адресом. Заказ с отсутствующими или некорректными сохранёнными реквизитами требует ручной сверки платежа.

Статус оплаты определяется серверной проверкой платежа. Адрес уведомлений должен принимать POST JSON и заголовок `x-signature` без перенаправлений и проверок браузера. Актуальные IP-адреса отправителей для настройки сервера получите у [поддержки PassimPay](https://passimpay.io/en/contacts); требования описаны в [документации уведомлений](https://passimpay.gitbook.io/passimpay-api/webhook).

Platform ID и API Key доступны в настройках проекта в [кабинете PassimPay](https://account.passimpay.io/).
