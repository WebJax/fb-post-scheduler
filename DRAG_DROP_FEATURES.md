# Drag-and-Drop Funktionalitet i Facebook Post Scheduler

## Oversigt

Facebook Post Scheduler kalenderen understøtter nu fuldt drag-and-drop funktionalitet, der giver brugerne mulighed for at flytte planlagte opslag til forskellige datoer ved simpelthen at trække dem rundt i kalendervisningen.

## Funktioner

### 1. Drag-and-Drop Opslag

- **Drag Handle**: Hvert opslag i kalenderen har nu et drag handle (⊞ ikon), der vises ved hover
- **Visual Feedback**: Under trækning bliver opslaget semi-transparent og andre opslag bliver disabled
- **Drop Targets**: Alle kalenderdage fremhæves som potentielle drop-targets
- **Drag Preview**: Et roteret preview af opslaget følger musen under trækning

### 2. Visual Feedback

- **Drag Over Effect**: Kalenderdage fremhæves med blå border når man trækker over dem
- **Success Animation**: Grøn animation når flytningen lykkes
- **Error Animation**: Rød animation hvis flytningen fejler
- **Loading States**: Loading indicator på opslaget under AJAX-kald

### 3. Validering og Sikkerhed

- **Dato Validering**: Kan ikke flytte opslag til fortidige datoer
- **Nonce Verification**: Alle AJAX-kald er sikret med WordPress nonces
- **Tilladelser**: Kun brugere med 'edit_posts' rettigheder kan flytte opslag
- **Database Integritet**: Validering af dato format og post ID's

### 4. Responsive Design

- **Mobile Support**: Drag handles er altid synlige på mobile enheder
- **Touch Compatibility**: Fungerer med touch-baserede enheder
- **Improved Spacing**: Bedre afstand mellem opslag på mobile enheder

## Teknisk Implementation

### Frontend (JavaScript)

**Fil**: `assets/js/calendar.js`

- `setupDragHandlers()` - Initialiserer drag-and-drop event handlers
- `setupDropTarget()` - Sætter drop targets op for kalenderdage
- `movePostToDate()` - Håndterer AJAX-kald til at flytte opslag
- `showNotification()` - Viser feedback til brugeren

### Backend (PHP)

**Fil**: `includes/ajax-handlers.php`

- `fb_post_scheduler_move_post_ajax()` - AJAX handler til at flytte opslag
- Validering af dato format (YYYY-MM-DD)
- Tjek for fortidige datoer
- Database opdatering med ny scheduled_time

### Styling (CSS)

**Fil**: `assets/css/admin.css`

- Drag handles og visual states
- Drop target fremhævning
- Animation for success/error feedback
- Responsive forbedringer
- Loading states

## Brugsanvisning

### For Brugere

1. **Gå til kalender siden** i WordPress admin under "FB Opslag → Kalender"
2. **Find et planlagt opslag** i kalendervisningen
3. **Hover over opslaget** for at se drag handle (⊞ ikon)
4. **Træk opslaget** til en ny dato i kalenderen
5. **Slip opslaget** på den ønskede dato
6. **Vent på bekræftelse** - en grøn animation viser success

### Begrænsninger

- Kan ikke flytte opslag til fortidige datoer
- Kan ikke flytte allerede postede opslag
- Kræver JavaScript for at fungere
- Bevarer den originale tid når opslaget flyttes (kun datoen ændres)

## Error Handling

### Frontend Fejl

- **AJAX Timeout**: "Der opstod en fejl ved kommunikation med serveren"
- **Server Error**: Viser specifikke fejlbeskeder fra backend
- **Network Error**: Generisk netværksfejl besked

### Backend Fejl

- **Ugyldig Dato**: "Ugyldig dato format"
- **Fortidige Datoer**: "Du kan ikke flytte et opslag til en dato i fortiden"
- **Manglende Parametre**: "Manglende parametre for flytning af opslag"
- **Database Fejl**: "Fejl ved flytning af opslaget. Prøv igen senere."

## Performance Overvejelser

- Drag preview elementer fjernes automatisk efter brug
- Event handlers bruger namespace (.fbcalendar) for nem cleanup
- AJAX-kald inkluderer loading states for at informere brugeren
- Minimal DOM manipulation under drag operationer

## Browser Support

- **Modern Browsers**: Chrome, Firefox, Safari, Edge (alle nyere versioner)
- **HTML5 Drag API**: Kræver browsers der understøtter HTML5 drag-and-drop
- **Fallback**: Hvis drag-and-drop ikke understøttes, kan brugere stadig redigere opslag via edit-knappen

## Fremtidige Forbedringer

- **Batch Move**: Mulighed for at flytte flere opslag ad gangen
- **Date Picker**: Option for at vælge specifik tid når opslag flyttes
- **Undo Functionality**: Fortryd funktion for flytninger
- **Keyboard Support**: Understøttelse af keyboard navigation og flytning

## Debugging

For at aktivere debug information:

1. Aktivér WordPress debug mode i `wp-config.php`
2. Tjek browser console for JavaScript fejl
3. Tjek WordPress debug log for PHP fejl
4. Verificer at AJAX URLs og nonces er korrekte

## Changelog

### Version 1.0.0 (Juni 2025)
- Initial implementation af drag-and-drop funktionalitet
- Support for flytning af opslag mellem datoer
- Visual feedback og animationer
- Komplet error handling og validering
- Responsive design forbedringer
