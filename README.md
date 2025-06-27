# Facebook Post Scheduler

## Beskrivelse
Facebook Post Scheduler er et WordPress-plugin, der giver dig mulighed for at planlægge og administrere Facebook-opslag direkte fra WordPress. Du kan tilføje Facebook-opslagstekst til indhold fra forskellige post types og planlægge, hvornår opslagene skal postes til Facebook. Pluginet understøtter nu også AI-genereret opslag med Google Gemini 2.0 Flash.

## Funktioner
- Vælg hvilke post types der skal kunne planlægge Facebook-opslag
- Tilføj Facebook-opslagstekst direkte i indholdsredigeringen
- Planlæg, hvornår opslaget skal sendes til Facebook
- AI-genereret opslagstekst med Google Gemini 2.0 Flash
- **Automatisk Facebook Page selection og token management** 🆕
- **Detaljeret Facebook App setup guide med rettigheder** 🆕
- **"Forny token" knap for nemt token management** 🆕
- Automatisk tilføjelse af link til den originale indholdside
- Vedhæft et specifikt billede til Facebook-opslaget
- Planlæg flere Facebook-opslag til samme indhold
- Kalenderoversigt over alle planlagte opslag (månedlig og ugentlig visning)
- Kopier, flyt og slet opslag direkte i kalenderoversigten
- Dashboard for hurtig oversigt over planlagte opslag
- **Slet planlagte opslag direkte fra admin listen** 🆕
- **Facebook delings-kolonne på post oversigter** - se hvor mange gange hvert indlæg er blevet delt 🆕
- Robust Facebook API connection testing og token expiry checks
- Long-term access token exchange og management
- Eksporter planlagte opslag til CSV
- Notifikationssystem for opslagsstatus
- Logfil over alle Facebook API-kald og opslagsstatus

## Installation
1. Upload `fb-post-scheduler`-mappen til `/wp-content/plugins/` mappen
2. Aktivér pluginet via 'Plugins'-menuen i WordPress
3. Gå til 'FB Opslag' > 'Indstillinger' for at konfigurere pluginet

## Konfiguration

### 1. Facebook App Setup
Før du kan bruge pluginet, skal du have en Facebook App med de korrekte rettigheder. Pluginet inkluderer en detaljeret setup-guide:

1. **Opret Facebook App**: Gå til [Facebook for Developers](https://developers.facebook.com/apps/) og opret en ny app af typen "Business"
2. **Tilføj produkter**: Tilføj Facebook Login, Instagram Basic Display og Business Manager API
3. **Anmod om rettigheder**: Din app skal have følgende rettigheder:
   - `pages_show_list` - Se liste over sider
   - `business_management` - Administrer virksomhedskonti
   - `instagram_basic` - Grundlæggende Instagram adgang
   - `instagram_content_publish` - Udgiv Instagram indhold
   - `pages_read_engagement` - Læs side-engagement
   - `pages_manage_metadata` - Administrer side-metadata
   - `pages_read_user_content` - Læs brugerindhold på siden
   - `pages_manage_posts` - Administrer opslag på siden
   - `pages_manage_engagement` - Administrer side-engagement

4. **Hent App ID og App Secret**: Find disse i din apps "Basic Settings" sektion

### 2. Automatisk Facebook Side Setup 🆕
Pluginet inkluderer nu en ny funktion til at automatisk vælge Facebook-sider og generere long-term access tokens:

1. **Indtast App oplysninger**: Udfyld Facebook App ID og App Secret
2. **Bruger Access Token**: Hent et bruger access token fra [Graph API Explorer](https://developers.facebook.com/tools/explorer/) med de nødvendige rettigheder og gem det
3. **Indlæs sider**: Klik "Indlæs tilgængelige sider" for at se alle sider du har adgang til
4. **Vælg side**: Vælg den ønskede Facebook-side fra dropdown-menuen
5. **Automatisk konfiguration**: Pluginet genererer automatisk et long-term page access token og opdaterer alle indstillinger

### 3. Andre indstillinger
1. **Vælg Post Types**: Vælg hvilke post types der skal kunne oprette Facebook-opslag
2. **Test Facebook API Forbindelse**: Klik på "Test Facebook API Forbindelse" knappen for at verificere at dine indstillinger virker korrekt
3. **AI Tekst Generator Indstillinger**: Aktivér AI-tekstgenerering og indtast din Google Gemini API-nøgle

### Token Fornyelse
For eksisterende opsætninger kan du forny dit page access token ved at:
1. Opdatere dit bruger access token hvis nødvendigt
2. Klikke på "Forny Token" knappen under den aktuelle side-information
3. Dette henter et nyt long-term token automatisk

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
Pluginet understøtter generering af Facebook-opslagstekst med Google Gemini 2.0 Flash AI. For at bruge denne funktion:

1. Gå til 'FB Opslag' > 'Indstillinger' > 'AI Tekst Generator Indstillinger'
2. Aktivér AI tekstgenerering
3. Indtast din Google Gemini API-nøgle (kan fås fra [Google AI Studio](https://ai.google.dev/))
4. Tilpas AI prompten efter behov
5. Ved oprettelse eller redigering af indlæg kan du nu klikke på "Generer tekst med Gemini AI" knappen for at lade AI'en generere et relevant Facebook-opslag baseret på dit indhold

## Facebook API Test

Pluginet inkluderer nu en omfattende test-funktion, der gør det muligt at verificere din Facebook API forbindelse direkte fra indstillingssiden. Test-funktionen tjekker:

1. **Access Token Validering**: Verificerer at dit access token er gyldigt
2. **Side Information**: Henter information om din Facebook-side (navn, kategori, følgere)
3. **Posting Tilladelser**: Bekræfter at du har tilladelse til at poste på siden
4. **Token Udløb**: Tjekker hvornår dit access token udløber

### Long-term Access Tokens

Facebook access tokens udløber regelmæssigt. Pluginet understøtter nu automatisk udveksling til long-term tokens:

- **Short-term tokens**: Udløber efter 1-2 timer
- **Long-term tokens**: Udløber efter 60 dage
- **Automatisk advarsel**: Får besked når token snart udløber
- **Nem udveksling**: Udveksle tokens direkte fra indstillingssiden

**Sådan bruger du long-term tokens:**
1. Få et short-term access token fra Facebook Graph API Explorer eller din app
2. Gå til 'FB Opslag' > 'Indstillinger' > 'Facebook API Indstillinger'
3. Indsæt short-term token i "Long-term Access Token" sektionen
4. Klik "Udveksle til Long-term Token"
5. Gem indstillingerne

Gå til 'FB Opslag' > 'Indstillinger' > 'Facebook API Indstillinger' og brug de tilgængelige test-funktioner efter du har udfyldt alle felter.

## Nye funktioner

### Slet planlagte opslag fra admin listen
Du kan nu slette planlagte opslag direkte fra listen over "Kommende Facebook-opslag" ved at klikke på den røde "Slet" knap. Dette giver hurtig adgang til at fjerne opslag uden at skulle redigere det originale indhold.

### Facebook delings-kolonne
I oversigterne for posts, sider og events kan du nu se en "FB Delinger" kolonne, der viser hvor mange gange hvert indlæg er blevet delt på Facebook. Kolonnen kan sorteres og viser:
- Et blåt tal for indlæg der er blevet delt
- "0" for indlæg der ikke er blevet delt endnu
- Tooltips med yderligere detaljer

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
