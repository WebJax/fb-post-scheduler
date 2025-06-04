# Test Guide for Drag-and-Drop Funktionalitet

## Trinvise Test Instruktioner

### 1. Forberedelse
1. Log ind i WordPress admin på http://localhost:8000/wp-admin
2. Naviger til "FB Opslag → Kalender"
3. Sørg for at der er mindst ét planlagt opslag i kalenderen

### 2. Opret Test Opslag (hvis ingen findes)
1. Gå til "Indlæg → Tilføj nyt"
2. Skriv en titel og noget indhold
3. Scroll ned til "Facebook Post Scheduler" metaboxen
4. Aktivér "Aktiver Facebook-opslag"
5. Indstil en dato i fremtiden
6. Skriv noget tekst til Facebook opslaget
7. Gem indlægget

### 3. Test Drag-and-Drop Funktionalitet

#### Test 1: Grundlæggende Drag-and-Drop
1. Gå til kalender siden
2. Find et planlagt opslag (blå boks i kalenderen)
3. Hover over opslaget - du skulle se:
   - Et drag handle (⊞ ikon) vises på venstre side
   - Musen ændrer til grab cursor
4. Klik og træk opslaget til en anden dato
5. Slip opslaget - du skulle se:
   - Grøn animation på drop-target
   - Success besked øverst på siden
   - Opslaget vises nu på den nye dato

#### Test 2: Visual Feedback
1. Start med at trække et opslag
2. Mens du trækker, observer:
   - Opslaget bliver semi-transparent (dragging state)
   - Andre opslag bliver disabled (grået ud)
   - Alle kalenderdage får purple border (drop targets)
3. Træk over forskellige datoer og observer:
   - Dagen får blå border (drag-over state)
   - Border skifter tilbage når du forlader dagen

#### Test 3: Error Handling
1. Prøv at trække et opslag til en dato i fortiden
2. Du skulle se:
   - Rød animation på drop-target
   - Error besked: "Du kan ikke flytte et opslag til en dato i fortiden"

#### Test 4: Loading States
1. Træk et opslag til en ny dato
2. Observer loading indicator på opslaget under AJAX-kald
3. Vent på bekræftelse

### 4. Test Responsive Funktionalitet (Valgfri)
1. Åbn udviklingsværktøjer i browser (F12)
2. Skift til mobile view (Ctrl+Shift+M i Chrome)
3. Genindlæs kalender siden
4. Observér at drag handles nu er altid synlige på mobile

### 5. Test Action Buttons
1. Hover over et opslag i kalenderen
2. Test følgende knapper:
   - **Edit** (blyant ikon): Skulle åbne WordPress post editor
   - **Copy** (side ikon): Skulle kopiere opslaget til dagen efter
   - **Delete** (papirkurv ikon): Skulle slette opslaget efter bekræftelse

## Forventede Resultater

### ✅ Success Kriterier
- Drag handle vises ved hover
- Opslag kan trækkes mellem datoer
- Visual feedback fungerer korrekt
- Success/error beskeder vises
- Database opdateres korrekt (opslag vises på ny dato efter genindlæsning)

### ❌ Fejl at Løse
- Ingen visual feedback
- JavaScript fejl i console
- AJAX fejl beskeder
- Opslag vises ikke på ny dato efter flytning

## Debug Information

### JavaScript Console Fejl
Hvis der er JavaScript fejl, tjek:
1. Browser console (F12 → Console tab)
2. Fejl relateret til `fbPostSchedulerData` eller `fb_post_scheduler_move_post`

### AJAX Fejl
Hvis AJAX ikke virker:
1. Tjek at WordPress er logget ind
2. Verificer at nonce er korrekt
3. Tjek netværks tab i udviklingsværktøjer

### CSS Problemer
Hvis styling ikke vises korrekt:
1. Tjek at admin.css er indlæst
2. Verificer at CSS klasser anvendes korrekt
3. Test i forskellige browsers

## Test Miljø
- WordPress: Lokal installation på localhost:8000
- Browser: Chrome/Firefox/Safari (moderne versioner)
- PHP: 8.3.17
- Plugin version: Nyeste med drag-and-drop funktionalitet
