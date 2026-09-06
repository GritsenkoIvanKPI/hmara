# Recenze z Google

Sekce s recenzemi se na webu zobrazí **jen když jsou nějaké recenze**.
Dokud profil žádné nemá, je celá sekce skrytá — web vypadá stejně jako
teď. Jakmile první recenze přijde, sekce se objeví sama.

```
prohlížeč → google-reviews.php → Places API
                  ↑         ↓
            config.php   google-reviews-cache.json
            (API klíč)   (obnovuje se 1× za 24 h)
```

## 1. Získat API klíč

1. <https://console.cloud.google.com/> → vytvořte projekt.
2. Zapněte **Places API (New)**.
3. **APIs & Services → Credentials → Create credentials → API key.**
4. Klíč omezte (tlačítko *Edit API key*):
   - **API restrictions** → jen *Places API (New)*
   - *Application restrictions* nechte na **None** — klíč používá server,
     ne prohlížeč, takže omezení na doménu by ho zablokovalo.
5. Google vyžaduje u projektu **fakturační údaje**. Při jednom dotazu
   denně se ale nevejdete ani do placeného pásma.

## 2. Zjistit place_id

Do `config.php` vložte klíč a otevřete v prohlížeči:

```
https://hmarabuild.cz/google-reviews.php?resolve=HMARA+BUILD
```

V odpovědi najdete `"id":"ChIJ..."`. To je `place_id`.

Když to nevyjde, dá se najít ručně:
<https://developers.google.com/maps/documentation/places/web-service/place-id>

## 3. Doplnit do config.php

```php
'google_api_key'  => 'AIza...',
'google_place_id' => 'ChIJ...',
```

## 4. Ověřit

```
https://hmarabuild.cz/google-reviews.php
```

- `{"ok":true,"configured":false,...}` → klíč ještě není vyplněný
- `{"ok":true,...,"reviews":[]}` → klíč funguje, profil zatím nemá recenze
- `{"ok":true,...,"reviews":[{...}]}` → hotovo, sekce se na webu zobrazí

Vynutit okamžitou obnovu cache: `google-reviews.php?refresh=1`

## Co je dobré vědět

- **Places API vrací maximálně 5 recenzí** a vybírá je Google. Víc jich
  oficiální cestou získat nelze.
- Recenze se stahují **jednou za 24 hodin** a ukládají do souboru
  `google-reviews-cache.json`. Šetří to peníze a web se tím nezpomalí.
- Když je Google nedostupný, web ukáže poslední uloženou verzi.
- Podmínky Google vyžadují u recenze **jméno autora a odkaz na Google** —
  obojí sekce zobrazuje, neodstraňujte to.
- `google-reviews-cache.json` se generuje sám, do gitu nepatří.
