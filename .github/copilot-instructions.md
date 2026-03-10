# Copilot Instructions – fb-post-scheduler

## Projektbeskrivelse
`fb-post-scheduler` er et **WordPress-plugin** skrevet i PHP og Vanilla JavaScript. Det lader redaktører planlægge Facebook-opslag direkte fra WordPress-editoren, understøtter AI-genereret tekst via Google Gemini (`gemini-1.5-flash`) og viser en kalender over planlagte opslag.

---

## Teknisk stack
| Lag | Teknologi |
|-----|-----------|
| Backend | PHP 7.0+, WordPress hooks/filters, `$wpdb` |
| Frontend | Vanilla JS (ES6), Custom CSS |
| Database | MySQL/MariaDB via WordPress |
| Ekstern API | Facebook Graph API (versionløs URL i koden), Google Gemini (`gemini-1.5-flash`) |
| Afhængighedsstyring | Ingen (Composer/npm bruges ikke) |
| Tests | Ingen automatiserede tests |
| CI/CD | Ingen pipeline |

---

## Mappestruktur
```
fb-post-scheduler/
├── fb-post-scheduler.php      # Plugin-entry point, konstanter, main class (singleton)
├── includes/
│   ├── ajax-handlers.php      # Alle wp_ajax_* handlers
│   ├── api-helper.php         # Facebook Graph API-kald
│   ├── db-helper.php          # CRUD mod wp_fb_scheduled_posts og wp_fb_post_scheduler_logs
│   ├── ai-helper.php          # Google Gemini API-integration
│   ├── notifications.php      # Notifikationssystem
│   ├── dashboard-widget.php   # WordPress dashboard-widget
│   ├── export.php             # CSV-eksport
│   └── migration.php          # DB-skema migrations (køres ved aktivering)
├── assets/
│   ├── js/
│   │   ├── admin.js           # Meta box, token management, AI-knapper
│   │   ├── calendar.js        # Kalender (måneds-/ugevisning)
│   │   └── notifications.js   # Frontend notifikationer
│   └── css/
│       └── admin.css          # Al admin-styling
└── logs/                      # Mappe i git; nye runtime-logfiler i denne mappe ignoreres af git via .gitignore
```

---

## Navngivningskonventioner
- **PHP-funktioner**: `fb_post_scheduler_*` (snake_case med prefix)
- **PHP-klasser**: `FB_Post_Scheduler*` (PascalCase)
- **WordPress options**: `fb_post_scheduler_*`
- **AJAX-actions**: `fb_post_scheduler_*`
- **CSS-klasser**: `fb-*` (kebab-case)
- **JS-variabler**: camelCase

---

## Kode-mønstre

### Singleton-klasse (main plugin)
```php
class FB_Post_Scheduler {
    private static $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'init' ] );
        // registrér alle hooks her
    }
}
```

### AJAX-handler (PHP-side)
Alle AJAX-handlers skal:
1. Verificere nonce med `wp_verify_nonce()`
2. Tjekke `current_user_can()` (minimum `edit_posts`)
3. Sanitere input (`sanitize_text_field()`, `intval()`, `absint()`)
4. Returnere via `wp_send_json_success()` / `wp_send_json_error()`

```php
add_action( 'wp_ajax_fb_post_scheduler_example', 'fb_post_scheduler_example_handler' );

function fb_post_scheduler_example_handler() {
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'fb_post_scheduler_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }
    $value = sanitize_text_field( $_POST['value'] ?? '' );
    // forretningslogik …
    wp_send_json_success( [ 'result' => $value ] );
}
```

### Database-forespørgsler
For alt **nyt eller ændret** kode skal du bruge `$wpdb->prepare()` til parameteriserede forespørgsler og **aldrig** rå string-interpolation i SQL.
Eksisterende legacy-forespørgsler, der stadig bruger interpoleret SQL (fx i `includes/export.php`), skal løbende refaktoreres til `$wpdb->prepare()`, når koden alligevel berøres.

```php
global $wpdb;
$table = $wpdb->prefix . 'fb_scheduled_posts';
$results = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND status = %s", $post_id, 'scheduled' )
);
```

### JavaScript AJAX-kald
```js
jQuery.post(
    fbPostScheduler.ajaxUrl,
    {
        action: 'fb_post_scheduler_example',
        nonce:  fbPostScheduler.nonce,
        value:  someValue,
    },
    function( response ) {
        if ( response.success ) {
            // håndtér success
        } else {
            console.error( response.data.message );
        }
    }
);
```

---

## Sikkerhedskrav (må aldrig fraviges)
- **Nonce på alt**: Alle AJAX-kald og formularindsendelser skal bruge WordPress nonces.
- **Capability-tjek**: Brug `current_user_can()` på serversiden – aldrig kun på klientsiden.
- **Sanitering**: Alle brugerindtastninger saniteres inden brug (`sanitize_*`, `intval`, `absint`).
- **Escaping ved output**: Brug `esc_html()`, `esc_attr()`, `esc_url()` ved output til HTML.
- **SQL**: Brug altid `$wpdb->prepare()` – aldrig rå string-interpolation.
- **Hemmeligheder**: Facebook tokens og API-nøgler gemmes i `wp_options` – aldrig i kode eller logs.
- **ABSPATH-tjek**: Alle PHP-filer starter med `if ( ! defined( 'ABSPATH' ) ) exit;`.

---

## Databasetabeller
| Tabel | Formål |
|-------|--------|
| `{prefix}fb_scheduled_posts` | Planlagte opslag med status, tidspunkt og besked |
| `{prefix}fb_post_scheduler_logs` | Log over alle API-kald og opslagsstatus |

Skemaændringer implementeres i `includes/migration.php` og køres via `register_activation_hook`.

---

## WordPress options (konfiguration)
Alle indstillinger læses/skrives via `get_option()` / `update_option()` med præfikset `fb_post_scheduler_`. Eksempler:

| Option | Indhold |
|--------|---------|
| `fb_post_scheduler_facebook_app_id` | Facebook App ID |
| `fb_post_scheduler_facebook_access_token` | Page Access Token |
| `fb_post_scheduler_gemini_api_key` | Google Gemini API-nøgle |
| `fb_post_scheduler_ai_enabled` | `'1'` / `''` |

---

## AJAX-endpoints (oversigt)
Alle endpoints følger mønstret `wp_ajax_fb_post_scheduler_<navn>` og er registreret i `includes/ajax-handlers.php`. Se filen for fuld liste.

---

## Fejlhåndtering
- Brug WordPress `WP_Error` til at indkapsle fejl fra API-kald.
- Log fejl og API-resultater til `/logs/fb-post-scheduler.log` via plugin-logfunktionen.
- Vis aldrig rå PHP-fejl eller stack traces til slutbrugere.

---

## Hvad Copilot skal gøre
- Følg WordPress Coding Standards (PSR-2 er **ikke** standarden her).
- Skriv **dansk** UI-tekst (labels, knapper, notifikationer) i tråd med det eksisterende plugin.
- Tilføj PHPDoc-kommentarer til alle nye funktioner og klasser.
- Brug eksisterende helper-filer (`api-helper.php`, `db-helper.php`, osv.) frem for at skrive logik direkte i `ajax-handlers.php` eller main-filen.
- Registrér nye scripts/styles kun via `wp_enqueue_scripts` / `admin_enqueue_scripts`-hooks.
- Tilføj nye admin-sider via `add_submenu_page()` under `fb-post-scheduler`-menuen.

## Hvad Copilot skal undgå
- Introducer **ikke** Composer, npm, Webpack eller andre build-tools med mindre eksplicit bedt om det.
- Undgå at introducere **nye** globale variabler i JavaScript – brug de eksisterende lokaliserede objekter (`fbPostScheduler`, `fbPostSchedulerData`, `fbPostSchedulerNotifications`, `fbPostSchedulerAuth`).
- Undgå direkte `echo` af unsaniteret brugerinput.
- Undgå `mysql_*`-funktioner – brug udelukkende `$wpdb`.
- Undgå hardkodede tabelnavne – brug altid `$wpdb->prefix . 'fb_...'`.
