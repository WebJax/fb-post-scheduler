=== Facebook Post Scheduler ===
Contributors: jacobthygesen
Tags: facebook, social media, scheduler, posts, automation
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 7.0
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Planlæg og administrer Facebook-opslag direkte fra WordPress med automatisk link til indholdet.

== Description ==

Facebook Post Scheduler er et WordPress-plugin, der giver dig mulighed for at planlægge og administrere Facebook-opslag direkte fra WordPress. Du kan tilføje Facebook-opslagstekst til indhold fra forskellige post types og planlægge, hvornår opslagene skal postes til Facebook.

= Hovedfunktioner =

* **Facebook Login Integration** - Log ind direkte med din Facebook-konto
* **Fleksibel Post Type Support** - Vælg hvilke post types der skal kunne planlægge Facebook-opslag
* **AI-genereret Indhold** - Automatisk tekstgenerering med Google Gemini AI
* **Avanceret Planlægning** - Planlæg flere opslag til samme indhold
* **Billede Support** - Vedhæft specifikke billeder til Facebook-linkets forhåndsvisning
* **Hybrid link-preview** - Se `og:image`, titel og beskrivelse og tjek Facebooks cache
* **Massehandlinger** - Flueben og “Slet valgte” på kommende og postede opslag
* **Revision-oprydning** - Fjerner planlagte rækker der ved en fejl peger på en WordPress-revision (“Udgave”)
* **Kalender Oversigt** - Se alle planlagte opslag i månedlig og ugentlig visning
* **Dashboard Widget** - Hurtig oversigt over kommende opslag
* **Export Funktionalitet** - Eksporter planlagte opslag til CSV
* **Notifikationssystem** - Hold styr på opslagsstatus
* **Detaljeret Logging** - Komplet logfil over alle Facebook API-kald

= Sådan fungerer det =

1. Log ind med din Facebook-konto direkte i pluginet
2. Vælg hvilke post types der skal kunne oprette Facebook-opslag
3. Når du opretter eller redigerer indhold, tilføj Facebook-opslagstekst
4. Planlæg dato og tidspunkt for opslaget
5. Vælg eventuelt et billede til link-previewen, og tjek forhåndsvisningen (eller Facebooks cache)
6. Gem indlægget - opslaget postes automatisk på det planlagte tidspunkt

= AI Integration =

Pluginet understøtter Google Gemini AI til automatisk generering af Facebook-opslagstekst:

* Aktivér AI-tekstgenerering i indstillingerne
* Tilpas AI-prompten efter dine behov
* Klik på "Generer tekst med Gemini AI" når du opretter opslag
* AI'en analyserer dit indhold og foreslår relevant Facebook-tekst

= Kalender og Administration =

* **Kalender Oversigt** - Se alle planlagte opslag i en overskuelig kalender
* **Interaktiv Administration** - Kopier, flyt og slet opslag direkte i kalenderen
* **Admin-lister** - Slet enkeltvis eller flere ad gangen på kommende og postede opslag
* **Export Funktioner** - Eksporter data til CSV for videre analyse
* **Dashboard Integration** - Widget med oversigt over kommende opslag

== Installation ==

1. Upload `fb-post-scheduler` mappen til `/wp-content/plugins/` biblioteket
2. Aktivér pluginet via 'Plugins' menuen i WordPress
3. Gå til 'FB Opslag' for at logge ind med Facebook
4. Konfigurer indstillinger under 'FB Opslag' > 'Indstillinger'

== Frequently Asked Questions ==

= Hvordan får jeg Facebook API-nøgler? =

Du skal oprette en Facebook-app på [Facebook for Developers](https://developers.facebook.com/) og anmode om nødvendige tilladelser til at poste på en Facebook-side. Alternativt kan du bruge den integrerede Facebook login-funktion.

= Hvordan får jeg en Google Gemini API-nøgle? =

Du kan få en Google Gemini API-nøgle ved at oprette en konto på [Google AI Studio](https://ai.google.dev/) og generere en API-nøgle der.

= Er der begrænsninger på AI-tekstgenerering? =

Ja, Google Gemini API har begrænsninger på antal forespørgsler. Se Google's dokumentation for aktuelle begrænsninger.

= Hvor ofte tjekker pluginet for opslag der skal postes? =

Pluginet tjekker hver time for planlagte opslag. Du kan også manuelt trigge et tjek fra admin-panelet.

= Kan jeg planlægge flere opslag til samme indlæg? =

Ja, du kan oprette flere Facebook-opslag til samme WordPress-indlæg med forskellige tidspunkter og tekster.

= Hvorfor står der “Udgave” i Type-kolonnen? =

“Udgave” er WordPress’ danske navn for en revision (gemt ændring). Ældre planlagte rækker kan pege på revisionen i stedet for selve indlægget. Under FB Opslag kan du fjerne dem med knappen til revision-opslag. Nye gem gemmes ikke længere på revisioner.

== Screenshots ==

1. Facebook Login integration i admin-panelet
2. Meta box til oprettelse af Facebook-opslag
3. Kalender oversigt over planlagte opslag
4. AI-tekstgenerering med Google Gemini
5. Dashboard widget med kommende opslag
6. Indstillingsside med alle konfigurationsmuligheder

== Changelog ==

= 1.1.7 =
* Hybrid forhåndsvisning af Facebook-linkkortet i metaboxen
* Preview bruger valgt billede, ellers sidens og:image, ellers udvalgt billede
* Titel og beskrivelse kommer fra Open Graph-tags, ikke kun WordPress-titlen
* Ny knap “Tjek hos Facebook” læser Facebooks cache uden at scrape
* “Opdater Facebooks cache” tvinger et nyt scrape efter bekræftelse
* Ens preview-DOM for gemte og nytilføjede opslag, så billedskift virker live
* Planlagte opslag gemmes ikke længere på WordPress-revisioner (“Udgave”)
* Knap til at fjerne eksisterende planlagte rækker knyttet til revisioner
* Flueben og massehandling “Slet valgte” på kommende og postede opslag
* Type-kolonnen viser forældreindlæggets type, og dobbelt type-tekst på postede opslag er rettet

= 1.1.6 =
* Gemte Facebook-sider (navn + Page ID) under Indstillinger
* @[søgning] i opslagsteksten indsætter @[PAGE_ID] fra gemte sider

= 1.0.0 =
* Første udgivelse
* Facebook login integration
* AI-tekstgenerering med Google Gemini
* Kalender oversigt
* Dashboard widget
* Export funktionalitet
* Notifikationssystem
* Detaljeret logging

== Upgrade Notice ==

= 1.2.0 =
Planlagte opslag gemmes ikke længere på revisioner, og du kan slette flere rækker ad gangen på FB Opslag-listerne.

= 1.1.7 =
Forhåndsvisningen i metaboxen viser nu det Facebook-linkkort, der ventes slået op (og:image, titel og beskrivelse), plus knapper til at tjekke eller opdatere Facebooks cache.

= 1.0.0 =
Første udgivelse af Facebook Post Scheduler. Ingen upgrades nødvendige.

== Support ==

For support og fejlrapportering, kontakt udvikleren på [jaxweb.dk](https://jaxweb.dk).

== Privacy Policy ==

Dette plugin gemmer Facebook API-nøgler og Google Gemini API-nøgler i WordPress-databasen. Sørg for at holde disse oplysninger sikre og brug kun pluginet på sikre websites med opdateret WordPress.

== Credits ==

Udviklet af Jacob Thygesen til brug på danske WordPress-hjemmesider.