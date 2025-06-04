# Facebook Post Scheduler

## Beskrivelse
Facebook Post Scheduler er et WordPress-plugin, der giver dig mulighed for at planlægge og administrere Facebook-opslag direkte fra WordPress. Du kan tilføje Facebook-opslagstekst til indhold fra forskellige post types og planlægge, hvornår opslagene skal postes til Facebook. Pluginet understøtter nu også AI-genereret opslag med Google Gemini.

## Funktioner
- Vælg hvilke post types der skal kunne planlægge Facebook-opslag
- Tilføj Facebook-opslagstekst direkte i indholdsredigeringen
- Planlæg, hvornår opslaget skal sendes til Facebook
- **AI-genereret opslagstekst med Google Gemini**
- Automatisk tilføjelse af link til den originale indholdside
- Vedhæft et specifikt billede til Facebook-opslaget
- Planlæg flere Facebook-opslag til samme indhold
- Kalenderoversigt over alle planlagte opslag (månedlig og ugentlig visning)
- Dashboard for hurtig oversigt over planlagte opslag
- Eksporter planlagte opslag til CSV
- Notifikationssystem for opslagsstatus
- Logfil over alle Facebook API-kald og opslagsstatus

## Installation
1. Upload `fb-post-scheduler`-mappen til `/wp-content/plugins/` mappen
2. Aktivér pluginet via 'Plugins'-menuen i WordPress
3. Gå til 'FB Opslag' > 'Indstillinger' for at konfigurere pluginet

## Konfiguration
1. **Vælg Post Types**: Vælg hvilke post types der skal kunne oprette Facebook-opslag
2. **Facebook API Indstillinger**: Indtast Facebook App ID, App Secret, Page ID og Access Token
3. **AI Tekst Generator Indstillinger**: Aktivér AI-tekstgenerering og indtast din Google Gemini API-nøgle

## Sådan bruges pluginet
1. Opret eller rediger et indlæg af en af de valgte post types
2. Find 'Facebook Opslag' boksen i indholdseditoren
3. Aktivér Facebook-opslag ved at klikke på checkboksen
4. Indtast teksten til Facebook-opslaget manuelt eller brug "Generer tekst med Gemini AI" knappen
5. Vælg dato og tidspunkt for opslaget
6. Vælg eventuelt et billede til opslaget
7. Gem indlægget
8. Tilføj flere opslag efter behov ved at klikke på "Tilføj endnu et opslag"

## AI-genererede Facebook-opslag
Pluginet understøtter nu generering af Facebook-opslagstekst med Google Gemini AI. For at bruge denne funktion:

1. Gå til 'FB Opslag' > 'Indstillinger' > 'AI Tekst Generator Indstillinger'
2. Aktivér AI tekstgenerering
3. Indtast din Google Gemini API-nøgle (kan fås fra [Google AI Studio](https://ai.google.dev/))
4. Tilpas AI prompten efter behov
5. Ved oprettelse eller redigering af indlæg kan du nu klikke på "Generer tekst med Gemini AI" knappen for at lade AI'en generere et relevant Facebook-opslag baseret på dit indhold

## Kalender
Du kan få en kalenderoversigt over alle planlagte Facebook-opslag ved at gå til 'FB Opslag' > 'Kalender' i WordPress admin-menuen. Her kan du se alle planlagte opslag og interagere med dem direkte. Du kan skifte mellem månedlig og ugentlig visning.

### Kalenderfunktioner:
- **Vis opslag**: Klik på titel for at redigere det tilknyttede WordPress-indlæg
- **Kopier opslag**: Hover over et opslag og klik på kopier-ikonet for at oprette en kopi planlagt til dagen efter
- **Slet opslag**: Hover over et opslag og klik på slet-ikonet for at fjerne det permanent
- **Rediger opslag**: Klik på rediger-ikonet for hurtig navigation til redigering

Alle handlinger kræver bekræftelse og kalenderen opdateres automatisk efter ændringer.

## Manuel kørsel af opslag
Hvis du ønsker at køre opslagsprocessen manuelt uden at vente på den timelige tjek, kan du gå til 'FB Opslag' og klikke på "Kør Facebook-opslag nu" knappen. Dette vil køre alle planlagte opslag, der er planlagt til at blive postet nu eller tidligere.

## Logs og fejlfinding
Pluginet opretter automatisk en logfil med oplysninger om alle opslag, der postes til Facebook, samt eventuelle fejl. Logfilen findes i `/wp-content/plugins/fb-post-scheduler/logs/` mappen.

## Krav
- WordPress 5.0 eller nyere
- PHP 7.0 eller nyere
- Adgang til Facebook API (App ID, App Secret, Page ID, Access Token)
- For AI-funktioner: Google Gemini API-nøgle

## Udviklet til
Pluginet er udviklet af Jacob Thygesen til brug på danske WordPress-hjemmesider.

## Sikkerhed
Dit Facebook API-nøgler, Google Gemini API-nøgle og tokens opbevares i WordPress-databasen. Sørg for at holde disse oplysninger sikre og brug kun pluginet på sikre websites med opdateret WordPress og relevante sikkerhedsforanstaltninger.

## FAQ

### Hvordan får jeg Facebook API-nøgler?
Du skal oprette en Facebook-app på [Facebook for Developers](https://developers.facebook.com/) og anmode om nødvendige tilladelser til at poste på en Facebook-side.

### Hvordan får jeg en Google Gemini API-nøgle?
Du kan få en Google Gemini API-nøgle ved at oprette en konto på [Google AI Studio](https://ai.google.dev/) og generere en API-nøgle der.

### Er der begrænsninger på AI-tekstgenerering?
Ja, Google Gemini API har begrænsninger på hvor mange forespørgsler du kan sende. Se Google's dokumentation for de aktuelle begrænsninger for din konto.

### Hvor ofte tjekker pluginet for opslag der skal postes?
Pluginet tjekker hver time for planlagte opslag, der skal postes til Facebook.

### Hvad sker der hvis et opslag ikke bliver postet?
Hvis et opslag ikke bliver postet, forbliver det i kalenderen og vil blive forsøgt postet igen ved næste tjek. Du kan også manuelt trigge et post ved at redigere og gemme indlægget igen.
