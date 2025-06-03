# Test Mode for Facebook Post Scheduler

Dette dokument beskriver hvordan du kan teste Facebook Post Scheduler plugin i et lokalt udviklingsmiljø uden adgang til Facebook API.

## Testfunktionalitet

Facebook Post Scheduler pluginnet understøtter en testfunktionalitet, der simulerer Facebook API uden at sende rigtige opslag til Facebook. Dette er nyttigt under udvikling og test.

### Aktivering af Testmode

Testmode er som standard aktiveret i koden:

```php
define('FB_POST_SCHEDULER_TEST_MODE', true);
```

Dette findes i hovedfilen `fb-post-scheduler.php`.

For at deaktivere testmode (i produktionsmiljø), skal du ændre denne værdi til `false`.

### Test Scripts

Pluginet indeholder to test scripts:

1. **WordPress-baseret test**: `/tests/test-facebook-posts.php`
   - Integrerer med WordPress for at teste alle aspekter af pluginet
   - Kan køres via `./run-test.sh`

2. **Standalone test**: `/tests/standalone-test.php`
   - Kører uden WordPress og tester kun API-klasserne
   - Kan køres via `./run-standalone-test.sh`

### Testlogfiler

Når du bruger testmode, bliver "opslag" til Facebook logget til filer i stedet for at blive sendt til Facebook. Disse logs gemmes i:

```
/wp-content/uploads/fb-post-scheduler-test-logs/
```

## Funktionalitet i Testmode

I testmode:

1. Alle API-kald til Facebook simuleres
2. Credentials bliver altid valideret som gyldige
3. Opslag logges lokalt i stedet for at blive sendt til Facebook
4. Et simuleret Facebook post ID genereres for hvert opslag
5. API returnerer et success-response lige som det rigtige API ville

## Arbejdsgang for Udvikling

1. Sørg for at `FB_POST_SCHEDULER_TEST_MODE` er sat til `true` under udvikling
2. Implementér og test din kode uden at bekymre dig om Facebook API forbindelser
3. Kør testscriptet via `./run-test.sh` for at sikre at alt fungerer korrekt
4. Når du er klar til at deploye i produktion, sæt `FB_POST_SCHEDULER_TEST_MODE` til `false`

## Sådan tester du Planlagte Opslag

1. Opret et nyt planlagt opslag via WordPress admin
2. Angiv en fremtidig dato/tid
3. Kør testscriptet igen for at se information om det planlagte opslag

## Fejlfinding

Hvis du oplever problemer med testfunktionaliteten:

1. Tjek at `FB_POST_SCHEDULER_TEST_MODE` er sat til `true`
2. Sørg for at test-logmappen er skrivbar
3. Se i WordPress fejllog for eventuelle fejlmeddelelser
