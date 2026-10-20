# Facebook Post Scheduler

WordPress-plugin der planlægger opslag til én Facebook-side. Tekst, tidspunkt og link-billede sættes på indlægget. Et timejob poster, når tiden er inde. Tekst kan genereres med Ollama (Gemma 4) eller Google Gemini.

## Funktioner

- Opslag på valgte post types, flere pr. indlæg
- Link til indlægget og billede til link-preview (`og:image`)
- Kalender (måned og uge) med kopier, flyt og slet
- Lister over kommende og postede opslag, enkeltvis eller med **Slet valgte**
- Notifikationer med mail, **Prøv igen** og **Slet** på fejlede opslag
- Kolonner på indlægsoversigter: antal opslag og Facebook-delinger
- CSV-eksport, dashboard-widget og log

Sletning i WordPress fjerner pluginets række. Den sletter ikke et opslag, der allerede ligger på Facebook.

## Installation

1. Læg mappen `fb-post-scheduler` i `/wp-content/plugins/`.
2. Aktivér pluginet.
3. Gå til **FB Opslag → Indstillinger**.

Indstillingerne er delt i faner: post types, Facebook, AI og notifikationer.

## Forbind siden

1. Opret en app af typen Business på [Facebook for Developers](https://developers.facebook.com/apps/) og tilføj Facebook Login.
2. Gem App ID og App Secret i fanen **Facebook API Indstillinger**.
3. I [Graph API Explorer](https://developers.facebook.com/tools/explorer/) skal bruger-tokenet have `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` og `business_management` (sider i en Business-konto).
4. Indsæt tokenet under **Forbind Facebook-side** og klik **Opret long-term side-token**. Én side gemmes med det samme. Flere sider vælges i listen.

Side-tokenet udløber ikke af sig selv. Det stopper, hvis kodeordet skiftes, rollen på siden mistes, eller appen fjernes. Lav et nyt token med samme knap. Page ID og token kan sættes manuelt under **Avanceret**.

**Test Facebook API Forbindelse** tjekker token, side og tilladelse til at poste.

## Opret et opslag

1. Redigér et indlæg af en valgt post type.
2. Aktivér boksen **Facebook Opslag**.
3. Skriv teksten, eller brug AI-knappen.
4. Vælg dato og tid. Uden eget billede bruges sidens `og:image`, ellers det udvalgte billede.
5. **Forhåndsvisning af opslag** viser det forventede link-kort. **Tjek hos Facebook** læser cachen. **Opdater Facebooks cache** tvinger et nyt scrape.
6. Gem. Flere opslag tilføjes med **Tilføj endnu et opslag**.

Cron kører hver time. **Kør Facebook-opslag nu** på **FB Opslag** poster det, der allerede er forfaldent.

## AI

Under **Indstillinger → AI Tekst Generator Indstillinger** vælges Ollama eller Gemini. Knappen i metaboxen følger valget.

Ollama kører lokalt: `brew install ollama`, `brew services start ollama`, `ollama run gemma4:latest`. Gemini bruger en nøgle fra [Google AI Studio](https://ai.google.dev/) og er det normale valg, når Ollama ikke kører på serveren.

## Administration

**Kalender** ligger under **FB Opslag → Kalender**. Titel åbner indlægget. Ikoner kopierer til dagen efter, sletter eller går til redigering.

**Lister** på **FB Opslag** har slet på rækken og massehandlingen **Slet valgte**. Står der **Udgave** i type-kolonnen, peger rækken på en WordPress-revision. Knappen over listen fjerner kun planlagte revisionsrækker, ikke postede opslag.

**Notifikationer** viser, om et opslag blev postet eller fejlede, med link til indholdet. Nye resultater kan samles i en mail. Modtageren sættes under **Indstillinger → Notifikationer**. Et fejlet opslag kan prøves igen eller slettes. Slet fjerner rækken og opslagets data i databasen, så forældede fejl ikke bliver liggende. Indlægget selv slettes ikke.

**@-tags:** Gem sidenavn og Page ID under Facebook-indstillingerne. I teksten skriver du `@[` og vælger siden. Ved publicering sendes `@[PAGE_ID]`. `@` plus mindst tre bogstaver søger også offentlige sider.

## Logs

Hændelser skrives til `/wp-content/plugins/fb-post-scheduler/logs/`.

## Krav

WordPress 5.0+, PHP 7.0+, Facebook-app med side-token. AI kræver Ollama på localhost eller en Gemini-nøgle. Nøgler og tokens ligger i WordPress-databasen.

Udviklet af Jacob Thygesen til danske WordPress-sites. Support: [jaxweb.dk](https://jaxweb.dk).

## App Review: Page Public Content Access

Tekst til Meta App Review. Engelsk, fordi reviewerne læser engelsk.

[Pages Search](https://developers.facebook.com/docs/pages-api/search-pages) · [Page Feed mentions](https://developers.facebook.com/docs/graph-api/reference/page/feed/) · [Page Public Content Access](https://developers.facebook.com/docs/apps/review/feature#reference-PAGES_ACCESS)

**App:** Facebook Post Scheduler (WordPress). **Feature:** Page Public Content Access. **Purpose:** A Page admin looks up public Pages they do not manage and inserts `@[PAGE_ID]` into a scheduled post.

`pages_show_list` and `pages_read_engagement` only return Pages the user manages. Public lookup uses `GET /pages/search`, which requires this feature. The editor would otherwise paste numeric IDs by hand.

1. The editor types `@` plus at least 3 characters in the Facebook text field.
2. WordPress calls Graph API. The browser never does.
3. `GET /v18.0/pages/search?q={term}&fields=id,name` with an app token (`{app-id}|{app-secret}`). A user token is tried first for managed Pages.
4. Only `id` and `name` are requested. No posts, comments, reactions or other Page content.
5. Up to 8 matches appear. Choosing one saves `@[PAGE_ID:Page Name]` in post meta.
6. On publish the name is stripped. `POST /{page-id}/feed` sends `@[PAGE_ID]` with `pages_manage_posts`.

Search results are not stored, cached or used for ads. Only a Page the editor picks is kept, inside the post text. Tokens stay on the server. Only users with `edit_posts` can search, and the request uses a nonce. The app does not crawl feeds, run bulk search or mention a Page without that click.

**Reviewer check:** connect the Page under **FB Opslag → Indstillinger**, edit a post, type `@` and three letters of a public Page the user does not manage, pick a result, and confirm the published post shows a blue @-mention.

| Item | Value |
|---|---|
| Endpoint | `GET /v18.0/pages/search` |
| Query | `q`, minimum 3 characters |
| Fields | `id`, `name` |
| Token | App access token for public search |
| Publish | `POST /{page-id}/feed` with `@[PAGE_ID]` |

## FAQ

**Hvor ofte postes der?** Hver time. Forfaldne opslag kan også køres med **Kør Facebook-opslag nu**.

**Hvad sker der, når et opslag fejler?** Det postes ikke igen af sig selv. Det ligger under **Notifikationer → Fejlede opslag**, hvor det kan prøves igen eller slettes.

**Hvorfor står der “Udgave”?** Det er den danske betegnelse for en revision. Fjern de gamle rækker med knappen under **FB Opslag**. Nye gem skriver ikke til revisioner.

**Hvor får jeg nøglerne?** Facebook-app på [developers.facebook.com](https://developers.facebook.com/). Gemini-nøgle i [Google AI Studio](https://ai.google.dev/).
