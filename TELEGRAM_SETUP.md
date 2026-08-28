# Napojení formuláře na Telegram

Formulář na webu posílá poptávky do Telegramu. Token bota **nikdy** není
v `index.html` — kdokoliv by si ho mohl přečíst přes „zobrazit zdroj“ a
převzít bota. Token žije jen na serveru v souboru `config.php`.

```
prohlížeč  →  send-form.php  →  api.telegram.org
                    ↑
               config.php (token, nikdy se necommituje)
```

## 1. ID skupiny

Skupina **Hmara Build Заявки** už je napojená, její ID je:

```
-5347964265
```

Kdybyste někdy zakládali novou skupinu: přidejte do ní bota, napište tam
zprávu a otevřete `https://api.telegram.org/bot<TOKEN>/getUpdates` —
v odpovědi najdete `"chat":{"id":-100...}`.

## 2. Nahrát soubory na Hostinger

Do `public_html/` nahrajte:

- `index.html`
- `send-form.php`
- `.htaccess`
- složku `images/` a ikony (`favicon*`, `icon-*`, `apple-touch-icon.png`,
  `og-image.jpg`, `site.webmanifest`, `robots.txt`, `sitemap.xml`)

## 3. Vytvořit config.php

V `public_html/` vytvořte `config.php` podle `config.example.php`:

```php
<?php
return [
    'bot_token' => 'sem vložte token od @BotFather',
    'chat_id' => '-100xxxxxxxxxx',
    'notify_email' => '',
    'rate_limit_per_hour' => 5,
];
```

Soubor vytvářejte přímo na serveru (File Manager v hPanelu). Do gitu
nepatří — je v `.gitignore`.

## 4. Otestovat

Odešlete poptávku z webu. Do skupiny musí přijít zpráva. Když ne,
podívejte se do error logu v hPanelu, `send-form.php` tam píše důvod.

## Co formulář umí

- serverová validace jména, telefonu, e-mailu a souhlasu se zpracováním údajů
- skrytá past na roboty (pole `website` — člověk ho nevidí)
- limit 5 odeslání za hodinu z jedné IP
- volitelná kopie poptávky na e-mail (`notify_email`)
- chybové stavy nikdy neprozradí token

## Bezpečnost

- Token neposílejte e-mailem ani chatem. Když unikne, v @BotFather dejte
  `/revoke` a vygenerujte nový — pak stačí přepsat `config.php`.
- `config.php` nikdy necommitujte.
