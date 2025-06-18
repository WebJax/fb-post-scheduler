=== Facebook Post Scheduler ===
Contributors: jacobthygesen
Tags: facebook, social media, scheduler, posts, automation
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
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
* **Billede Support** - Vedhæft specifikke billeder til Facebook-opslag
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
5. Vælg eventuelt et billede til opslaget
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

== Screenshots ==

1. Facebook Login integration i admin-panelet
2. Meta box til oprettelse af Facebook-opslag
3. Kalender oversigt over planlagte opslag
4. AI-tekstgenerering med Google Gemini
5. Dashboard widget med kommende opslag
6. Indstillingsside med alle konfigurationsmuligheder

== Changelog ==

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

= 1.0.0 =
Første udgivelse af Facebook Post Scheduler. Ingen upgrades nødvendige.

== Support ==

For support og fejlrapportering, kontakt udvikleren på [jaxweb.dk](https://jaxweb.dk).

== Privacy Policy ==

Dette plugin gemmer Facebook API-nøgler og Google Gemini API-nøgler i WordPress-databasen. Sørg for at holde disse oplysninger sikre og brug kun pluginet på sikre websites med opdateret WordPress.

== Credits ==

Udviklet af Jacob Thygesen til brug på danske WordPress-hjemmesider.