# Facebook Post Scheduler

## Beskrivelse
Facebook Post Scheduler er et WordPress-plugin, der giver dig mulighed for at planlægge og administrere Facebook-opslag direkte fra WordPress. Du kan tilføje Facebook-opslagstekst til indhold fra forskellige post types og planlægge, hvornår opslagene skal postes til Facebook.

## Funktioner
- Vælg hvilke post types der skal kunne planlægge Facebook-opslag
- Tilføj Facebook-opslagstekst direkte i indholdsredigeringen
- Planlæg, hvornår opslaget skal sendes til Facebook
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

## Sådan bruges pluginet
1. Opret eller rediger et indlæg af en af de valgte post types
2. Find 'Facebook Opslag' boksen i indholdseditoren
3. Aktivér Facebook-opslag ved at klikke på checkboksen
4. Indtast teksten til Facebook-opslaget
5. Vælg dato og tidspunkt for opslaget
6. Vælg eventuelt et billede til opslaget
7. Gem indlægget
8. Tilføj flere opslag efter behov ved at klikke på "Tilføj endnu et opslag"

## Kalender
Du kan få en kalenderoversigt over alle planlagte Facebook-opslag ved at gå til 'FB Opslag' > 'Kalender' i WordPress admin-menuen. Her kan du se alle planlagte opslag og klikke på dem for at redigere dem. Du kan skifte mellem månedlig og ugentlig visning.

## Manuel kørsel af opslag
Hvis du ønsker at køre opslagsprocessen manuelt uden at vente på den timelige tjek, kan du gå til 'FB Opslag' og klikke på "Kør Facebook-opslag nu" knappen. Dette vil køre alle planlagte opslag, der er planlagt til at blive postet nu eller tidligere.

## Logs og fejlfinding
Pluginet opretter automatisk en logfil med oplysninger om alle opslag, der postes til Facebook, samt eventuelle fejl. Logfilen findes i `/wp-content/plugins/fb-post-scheduler/logs/` mappen.

## Krav
- WordPress 5.0 eller nyere
- PHP 7.0 eller nyere
- Adgang til Facebook API (App ID, App Secret, Page ID, Access Token)

## Udviklet til
Pluginet er udviklet af Jacob Thygesen til brug på danske WordPress-hjemmesider.

## Sikkerhed
Dit Facebook API-nøgler og tokens opbevares i WordPress-databasen. Sørg for at holde disse oplysninger sikre og brug kun pluginet på sikre websites med opdateret WordPress og relevante sikkerhedsforanstaltninger.

## FAQ

### Hvordan får jeg Facebook API-nøgler?
Du skal oprette en Facebook-app på [Facebook for Developers](https://developers.facebook.com/) og anmode om nødvendige tilladelser til at poste på en Facebook-side.

### Hvor ofte tjekker pluginet for opslag der skal postes?
Pluginet tjekker hver time for planlagte opslag, der skal postes til Facebook.

### Hvad sker der hvis et opslag ikke bliver postet?
Hvis et opslag ikke bliver postet, forbliver det i kalenderen og vil blive forsøgt postet igen ved næste tjek. Du kan også manuelt trigge et post ved at redigere og gemme indlægget igen.
