# Facebook Post Scheduler

## Beskrivelse
Facebook Post Scheduler er et WordPress-plugin, der giver dig mulighed for at planlægge og administrere Facebook-opslag direkte fra WordPress. Du kan tilføje Facebook-opslagstekst til indhold fra forskellige post types og planlægge, hvornår opslagene skal postes til Facebook. Pluginet kan generere opslagstekst med lokal Ollama (Gemma 4) eller Google Gemini API.

## Funktioner
- Vælg hvilke post types der skal kunne planlægge Facebook-opslag
- Tilføj Facebook-opslagstekst direkte i indholdsredigeringen
- Planlæg, hvornår opslaget skal sendes til Facebook
- AI-genereret opslagstekst med Ollama (lokal Gemma 4) eller Google Gemini API
- Ét trin til et side-token uden udløb: indsæt et short-term bruger-token, og pluginet gemmer siden
- Opslag udgives på den forbundne Facebook-side
- Automatisk tilføjelse af link til den originale indholdside
- Vedhæft et specifikt billede til Facebook-opslaget
- Hybrid forhåndsvisning af Facebook-linkkortet (`og:image`, titel og beskrivelse) med knappen “Tjek hos Facebook”
- Planlæg flere Facebook-opslag til samme indhold
- Kalenderoversigt over alle planlagte opslag (månedlig og ugentlig visning)
- Kopier, flyt og slet opslag direkte i kalenderoversigten
- Dashboard for hurtig oversigt over planlagte opslag
- **Slet planlagte og postede opslag fra admin-listen** — enkeltvis eller med flueben og “Slet valgte”
- **Oprydning af revision-opslag** — fjerner planlagte rækker der ved en fejl peger på en WordPress-revision (“Udgave”)
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
Før du kan bruge pluginet, skal du have en Facebook-app:

1. **Opret Facebook App**: Gå til [Facebook for Developers](https://developers.facebook.com/apps/) og opret en ny app af typen "Business"
2. **Tilføj Facebook Login**, så du kan hente et bruger-token i Graph API Explorer
3. **Tilladelser** på bruger-tokenet:
   - `pages_show_list` - Se liste over sider
   - `pages_read_engagement` - Læs side-engagement
   - `pages_manage_posts` - Udgiv opslag på siden
4. **Hent App ID og App Secret**: Find disse under appens grundindstillinger, og gem dem i pluginet

### 2. Long-term side-token
Et side-token uden udløb laves sådan: short-term bruger-token → long-lived bruger-token → `GET /me/accounts`. Page-tokenet i svaret udløber ikke af sig selv.

1. Gem App ID og App Secret
2. Åbn [Graph API Explorer](https://developers.facebook.com/tools/explorer/), vælg appen, tilføj tilladelserne ovenfor, og generér et token
3. Indsæt tokenet under **Forbind Facebook-side**, og klik **Opret long-term side-token**
4. Har du én side, gemmes den med det samme. Har du flere, vælger du siden i listen
5. Status viser sidenavn og at tokenet udløber ikke. Brug **Test Facebook API Forbindelse** og **Tjek Token Udløb**

Tokenet holder op med at virke, hvis du skifter Facebook-kodeord, mister rollen på siden eller fjerner appen. Kør trinnet igen for at lave et nyt.

Grupper kan ikke længere nås via Graph API. Meta fjernede Groups API i april 2024, også for grupper en side administrerer eller følger. Opslag går derfor kun til siden. Allerede planlagte gruppe-opslag postes til siden.

Page ID og side-token kan rettes under **Avanceret**, hvis forbindelsen skal sættes manuelt.

### 3. Andre indstillinger
1. **Vælg Post Types**: Vælg hvilke post types der skal kunne oprette Facebook-opslag
2. **AI Tekst Generator Indstillinger**: Aktivér AI-tekstgenerering, vælg Ollama eller Gemini, og indtast Gemini API-nøgle hvis du bruger Gemini

## Sådan bruges pluginet
1. Opret eller rediger et indlæg af en af de valgte post types
2. Find 'Facebook Opslag' boksen i indholdseditoren
3. Aktivér Facebook-opslag ved at klikke på checkboksen
4. Indtast teksten til Facebook-opslaget manuelt eller brug AI-knappen (Ollama eller Gemini afhængigt af indstillingen)
5. Vælg dato og tidspunkt for opslaget
6. Vælg eventuelt et billede til opslaget (bruges som link-preview via `og:image`; ellers bruges sidens aktuelle Open Graph-billede)
7. Åbn **Forhåndsvisning af opslag** for at se det link-kort, Facebook forventes at vise. Kilde-labelen fortæller om billedet er valgt, kommer fra sidens `og:image` eller er udvalgt billede som fallback
8. Brug **Tjek hos Facebook** for at læse Facebooks cache uden at ændre den. **Opdater Facebooks cache** tvinger et nyt scrape (kræver bekræftelse)
9. Gem indlægget
10. Tilføj flere opslag efter behov ved at klikke på "Tilføj endnu et opslag"

## AI-genererede Facebook-opslag
Pluginet kan generere Facebook-opslagstekst med **Ollama** (localhost) eller **Google Gemini**. Vælg provider under indstillingerne. Knapteksten i metaboxen følger valget.

### Ollama (localhost)
1. Gå til 'FB Opslag' > 'Indstillinger' > 'AI Tekst Generator Indstillinger'
2. Aktivér AI-tekstgenerering
3. Vælg **Ollama (localhost)**
4. Installer Ollama og hent Gemma 4:
   - `brew install ollama`
   - `brew services start ollama`
   - `ollama run gemma4:latest`
5. Tilpas AI-prompten efter behov
6. Klik **Generer tekst med Ollama** i metaboxen

### Google Gemini API
1. Aktivér AI-tekstgenerering og vælg **Google Gemini API**
2. Indtast din API-nøgle fra [Google AI Studio](https://ai.google.dev/)
3. Tilpas AI-prompten efter behov
4. Klik **Generer tekst med Gemini** i metaboxen

Gemini er typisk det rigtige valg i produktion, hvor Ollama ikke kører på serveren.

## Facebook API Test

Pluginet inkluderer nu en omfattende test-funktion, der gør det muligt at verificere din Facebook API forbindelse direkte fra indstillingssiden. Test-funktionen tjekker:

1. **Access Token Validering**: Verificerer at dit access token er gyldigt
2. **Side Information**: Henter information om din Facebook-side (navn, kategori, følgere)
3. **Posting Tilladelser**: Bekræfter at du har tilladelse til at poste på siden
4. **Token Udløb**: Tjekker hvornår dit access token udløber

### Long-term side-token

Short-term bruger-tokens fra Graph API Explorer udløber efter 1–2 timer. Pluginet udveksler dem til et long-lived bruger-token og henter derefter sidens token via `GET /me/accounts`. Det side-token har ingen udløbsdato (`expires_at: 0`).

Det holder op med at virke, hvis Facebook-brugeren skifter kodeord, mister rollen på siden eller fjerner appen. Lav et nyt token med knappen **Opret long-term side-token**.

## Nye funktioner

### AI-provider: Ollama eller Gemini (1.2.0)
Under **FB Opslag → Indstillinger** kan du vælge om tekstgenerering skal bruge lokal Ollama eller Google Gemini API. Metabox-knappen skifter tekst efter valget. Gemini-nøglen vises kun når Gemini er valgt.

### Hybrid Facebook-link-preview (1.1.7)
Opslag postes som klikbare link-opslag. Previewen i metaboxen viser det kort, Facebook forventes at vise — ikke bare indlæggets udvalgte billede.

Prioritet for preview-billedet:
1. Det billede redaktøren har valgt til opslaget
2. Sidens aktuelle `og:image` (parsed fra permalink eller SEO-plugin)
3. Udvalgt billede som fallback

Titel og beskrivelse kommer fra `og:title` og `og:description`. **Tjek hos Facebook** læser Facebooks cache via Graph API uden `scrape=true`. **Opdater Facebooks cache** tvinger et nyt scrape.

### Massehandlinger på FB Opslag-listen (1.1.7)
På **FB Opslag** kan du vælge rækker med flueben på både **Kommende** og **Postede** opslag. Vælg **Slet valgte** i dropdownen **Massehandlinger** og klik **Udfør**. Der er også “vælg alle” i kolonneoverskriften. Enkelt-slet knappen findes stadig på hver række.

Sletning fjerner kun posten i WordPress/plugin-databasen — det sletter ikke opslaget på Facebook.

### Revisioner vises ikke længere som “Udgave”
Gutenberg kan gemme metabox-data på en WordPress-**revision**. I dansk WP hedder den type “Udgave”. Det gav dublerede planlagte rækker knyttet til revisionen i stedet for selve indlægget (fx en begivenhed).

Pluginnet ignorerer nu revision/autosave ved gem. Hvis der stadig ligger gamle rækker, vises knappen **Fjern X planlagte opslag knyttet til revisioner** over listen. Den sletter kun `scheduled`-rækker på revisioner — ikke postede opslag og ikke det rigtige indlæg.

### Slet planlagte opslag fra admin listen
Du kan slette planlagte og postede opslag direkte fra listerne på **FB Opslag** med den røde **Slet**-knap, eller flere ad gangen via massehandlingerne.

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
- For AI-funktioner: Ollama på localhost, eller en Google Gemini API-nøgle

## Udviklet til
Pluginet er udviklet af Jacob Thygesen til brug på danske WordPress-hjemmesider.

## Gemte sider til @-tags
Under **FB Opslag → Indstillinger** kan du gemme Facebook-sider med **side-navn** og **Page ID**.

I tekstfeltet til Facebook-opslag skriver du `@[` efterfulgt af (en del af) navnet — f.eks. `@[Skoring` — og vælger siden i listen. Pluginet indsætter `@[PAGE_ID:Side-navn]`, som ved publicering sendes til Facebook som `@[PAGE_ID]`.

Du kan stadig søge offentlige sider via Facebook med `@` + mindst 3 bogstaver (kræver App Review-tilladelser).

## Sikkerhed
Dit Facebook API-nøgler, Google Gemini API-nøgle og tokens opbevares i WordPress-databasen. Sørg for at holde disse oplysninger sikre og brug kun pluginet på sikre websites med opdateret WordPress og relevante sikkerhedsforanstaltninger.

## App Review: Page Public Content Access

This section is the description to paste into Meta App Review for the **Page Public Content Access** feature. English is included because Meta reviewers read English.

**Related docs:** [Pages Search](https://developers.facebook.com/docs/pages-api/search-pages) · [Page Feed mentions](https://developers.facebook.com/docs/graph-api/reference/page/feed/) · [Page Public Content Access](https://developers.facebook.com/docs/apps/review/feature#reference-PAGES_ACCESS)

---

### Feature use (copy for Meta App Review)

**App name:** Facebook Post Scheduler (WordPress plugin)  
**Feature requested:** Page Public Content Access  
**User-facing purpose:** Let a Page admin look up *public Facebook Pages they do not manage* and insert an official @-mention (`@[PAGE_ID]`) into a scheduled post.

#### Why this feature is required

Facebook Post Scheduler is used by website editors (for example a municipality or local news site) to compose and schedule posts that are published as the organization’s Facebook Page via `POST /{page-id}/feed`.

Editors often need to tag **official public Pages they do not administer** — for example a royal household, a government agency, a museum, or a partner organization — so the mention appears as a blue, clickable @-tag on Facebook.

`pages_read_engagement` and `pages_show_list` only return Pages the logged-in user already manages. They cannot resolve a public Page such as “Dronninglund” or “Meteo” to a Page ID. The Graph API Pages Search endpoint (`GET /pages/search`) requires **Page Public Content Access** or **Page Public Metadata Access** for that lookup.

Without this feature, the editor would have to find and paste raw numeric Page IDs by hand, which is error-prone and not usable in a CMS workflow.

#### What the app does with the feature (and nothing else)

1. A WordPress editor opens a post and types in the plugin’s Facebook post text field.
2. When they type `@` followed by at least 3 characters (example: `@Mete`), the plugin sends an authenticated admin AJAX request to WordPress. The browser never calls Graph API directly.
3. The WordPress server calls:

   `GET https://graph.facebook.com/v18.0/pages/search?q={search_term}&fields=id,name`

   using an app access token (`{app-id}|{app-secret}`). A user token is tried first for Pages the user already manages; public search uses the app token once this feature is approved.

4. **Only two fields are requested:** `id` (Facebook Page ID) and `name` (Page name). The app does **not** request or store posts, comments, reactions, followers, emails, or any other Page content.
5. Up to 8 matching Pages are shown in an autocomplete dropdown (name + ID). No other Graph data is displayed.
6. If the editor selects a Page, the plugin inserts `@[PAGE_ID:Page Name]` into the draft text (for example `@[123456789:Météo-France]`). That string is saved with the WordPress post as ordinary post meta.
7. At publish time the name is stripped and the Graph `message` parameter is sent as Meta’s official mention syntax, e.g. `Check out this article about @[123456789]!`, together with `pages_manage_posts` on `POST /{page-id}/feed`. Facebook then renders the blue @-tag.

#### Data use, storage, and retention

- Search is **on-demand and ephemeral**. Query results are not cached, not written to a database, and not used for analytics, advertising, scraping, or building a Page directory.
- The only persisted values are the Page ID (and optionally the display name) that the editor explicitly chose, stored inside the scheduled post’s message text in WordPress.
- Access tokens stay on the server (WordPress options). They are never exposed to site visitors.
- The feature is available only to logged-in WordPress users with the `edit_posts` capability. Requests are protected with a WordPress nonce.

#### What this app does not do

- It does not crawl, archive, or display public Page feed posts, photos, videos, or comments.
- It does not read Pages in bulk or run unattended search jobs.
- It does not mention Pages without an explicit editor action (selecting a result or typing `@[PAGE_ID]`).
- It does not use public Page data for ads, lookalike audiences, or resale.

#### Step-by-step for reviewers (screencast script)

1. Log in to WordPress as an editor.
2. Open **FB Opslag → Indstillinger** and confirm the Facebook App ID, App Secret, and Page are connected.
3. Create or edit a WordPress post.
4. In the **Facebook Opslag** meta box, enable the post and click the text field **Tekst til Facebook-opslag**.
5. Type `@` plus at least three letters of a **public Page the connected user does not manage**.
6. Confirm a dropdown of matching public Pages (name + ID) appears.
7. Click a result. Confirm the field contains `@[PAGE_ID:Page Name]`.
8. Schedule or publish. Confirm the Facebook post shows a blue @-mention of that Page.

#### API summary

| Item | Value |
|---|---|
| Endpoint | `GET /v18.0/pages/search` |
| Query | `q` = characters typed after `@` (minimum 3) |
| Fields | `id`, `name` only |
| Token | App access token (`{app-id}|{app-secret}`) for public search |
| Publish | `POST /{page-id}/feed` with `message` containing `@[PAGE_ID]` |
| Related permission | `pages_manage_posts` (already used to publish as the Page) |

---

## FAQ

### Hvordan får jeg Facebook API-nøgler?
Du skal oprette en Facebook-app på [Facebook for Developers](https://developers.facebook.com/) og anmode om nødvendige tilladelser til at poste på en Facebook-side.

### Hvor ofte tjekker pluginet for opslag der skal postes?
Pluginet tjekker hver time for planlagte opslag, der skal postes til Facebook.

### Hvad sker der hvis et opslag ikke bliver postet?
Hvis et opslag ikke bliver postet, forbliver det i kalenderen og vil blive forsøgt postet igen ved næste tjek. Du kan også manuelt trigge et post ved at redigere og gemme indlægget igen.

### Hvorfor står der “Udgave” i Type-kolonnen?
“Udgave” er WordPress’ danske navn for post typen `revision` (en gemt ændring). Ældre planlagte rækker kan pege på en revision i stedet for selve indlægget. Brug knappen til at fjerne dem under **FB Opslag**. Nye gem påvirker ikke længere revisioner.
