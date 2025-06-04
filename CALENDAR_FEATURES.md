# Kalender Features - Facebook Post Scheduler

## Nye Funktioner i Kalenderen

### Kopiering og Sletning af Opslag

Der er nu tilføjet mulighed for at kopiere og slette planlagte Facebook-opslag direkte fra kalendervisningen.

#### Nye Features:

1. **Action Knapper på Calendar Events**
   - Når du hover over et opslag i kalenderen vises tre action-knapper:
     - **Rediger** (blyant-ikon): Åbner WordPress post-editoren for det tilknyttede indlæg
     - **Kopier** (side-ikon): Opretter en kopi af opslaget planlagt til dagen efter
     - **Slet** (skraldespand-ikon): Sletter opslaget permanent

2. **Kopiering af Opslag**
   - Klik på "Kopier" knappen på et opslag
   - En bekræftelsesdialog vises
   - Det kopierede opslag oprettes med samme indhold men planlagt til dagen efter det oprindelige
   - Kalenderen opdateres automatisk for at vise det nye opslag

3. **Sletning af Opslag**
   - Klik på "Slet" knappen på et opslag
   - En bekræftelsesdialog vises med titlen på opslaget
   - Opslaget slettes permanent fra databasen
   - Kalenderen opdateres automatisk for at fjerne det slettede opslag

#### Tekniske Implementering:

- **AJAX Handlers**: `fb_post_scheduler_copy_post_ajax()` og `fb_post_scheduler_delete_post_ajax()`
- **JavaScript Funktioner**: `copyPost()` og `deletePost()` i calendar.js
- **CSS Styling**: Hover-baserede action-knapper med responsive design

#### Sikkerhed:

- Alle AJAX-kald valideres med nonce for sikkerhed
- Brugerrettigheder tjekkes før udførelse af handlinger
- Bekræftelsesdialogger forhindrer utilsigtede handlinger

#### Database Operationer:

- Kopiering gemmer nyt opslag med højeste index + 1
- Sletning fjerner direkte fra `fb_scheduled_posts` tabellen
- Alle operationer logger til WordPress debug hvis aktiveret

## Installation og Opdatering

Disse features er automatisk tilgængelige når pluginet er aktiveret. Ingen yderligere konfiguration er nødvendig.

## Kompatibilitet

- Fungerer med både månedlig og ugentlig kalendervisning
- Kompatibel med eksisterende database-struktur
- Understøtter alle typer af planlagte opslag (med og uden billeder)

## CSS Klasser

Nye CSS-klasser til styling:
- `.calendar-event .event-title` - Titel på opslag
- `.calendar-event .event-actions` - Container for action-knapper
- `.event-action` - Basis styling for action-knapper
- `.event-action.event-edit`, `.event-action.event-copy`, `.event-action.event-delete` - Specifikke knapper
- `.calendar-event.loading` - Loading state

## Browser Support

Funktionerne understøttes i alle moderne browsere og bruger standard WordPress admin styling og JavaScript.
