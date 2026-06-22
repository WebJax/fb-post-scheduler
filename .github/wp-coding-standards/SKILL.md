---
name: wp-coding-standards
description: Coding standards for Wordpress plugin development. Use when creating, editing, reviewing code for this wordpress plugin
---

---

## Table of Contents

1. [General Principles](#1-general-principles)
2. [File & Folder Structure](#2-file--folder-structure)
3. [PHP & WordPress](#3-php--wordpress)
4. [HTML](#4-html)
5. [CSS](#5-css)
6. [JavaScript (ES6+)](#6-javascript-es6)
7. [Comments & Documentation](#7-comments--documentation)
8. [Version Control (Git)](#8-version-control-git)
9. [Performance & Accessibility](#9-performance--accessibility)
10. [Tooling & Linting](#10-tooling--linting)

---

## 1. General Principles

- **Readability first.** Code is written once, read many times.
- **Consistency over preference.** Follow the project's established pattern even when you disagree.
- **Explicit over clever.** Avoid "magic" or overly terse constructs.
- **One thing per unit.** Functions, classes, and files should do one thing well.
- **Leave it better.** Apply the Boy Scout Rule: improve what you touch.

---

## 2. File & Folder Structure

### Naming conventions

| Type | Convention | Example |
|---|---|---|
| PHP files | `kebab-case.php` | `admin-settings.php` |
| CSS files | `kebab-case.css` | `theme-styles.css` |
| JS files | `kebab-case.js` | `product-gallery.js` |
| Classes (PHP) | `PascalCase` | `class PluginLoader` |
| Functions (PHP/JS) | `snake_case` / `camelCase` | `get_post_data()` / `fetchUserData()` |
| CSS classes | `BEM` or `kebab-case` | `.card__title`, `.site-header` |
| Constants | `UPPER_SNAKE_CASE` | `MAX_RETRIES` |

### WordPress plugin structure (recommended)

```
my-plugin/
├── my-plugin.php          # Main plugin file (header + bootstrap only)
├── includes/
│   ├── class-my-plugin.php
│   ├── admin/
│   └── frontend/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── templates/             # Reusable template partials
├── languages/             # .pot / .po files
└── README.md
```

---

## 3. PHP & WordPress

### General PHP

- Use **PHP 8.0+** features where the environment allows (named arguments, nullsafe operator, match expressions).
- Always use `strict_types=1` at the top of PHP files:
  ```php
  <?php
  declare(strict_types=1);
  ```
- Use **type hints** on function arguments and return types.
- Never use short open tags (`<?`). Always use `<?php`.

### WordPress-specific rules

**Prefix everything** to avoid conflicts. Use a short, unique prefix for all functions, classes, hooks, and global variables.

```php
// Bad
function get_settings() { ... }

// Good
function myplugin_get_settings() { ... }
```

**Escape all output** using the appropriate function:

| Context | Function |
|---|---|
| HTML output | `esc_html()` |
| HTML attributes | `esc_attr()` |
| URLs | `esc_url()` |
| JavaScript | `esc_js()` |
| Translated strings | `esc_html__()` / `esc_html_e()` |

**Sanitize all input** before using or saving data:

```php
$name = sanitize_text_field( $_POST['name'] ?? '' );
$url  = esc_url_raw( $_POST['redirect'] ?? '' );
```

**Nonces** are required for all form submissions and AJAX actions:

```php
// Output
wp_nonce_field( 'myplugin_save_options', 'myplugin_nonce' );

// Verify
check_admin_referer( 'myplugin_save_options', 'myplugin_nonce' );
```

**Hooks over direct calls:**

```php
// Bad — calling directly from another file
echo render_my_widget();

// Good — using hooks
add_action( 'wp_footer', 'myplugin_render_widget' );
```

**Database queries** always use `$wpdb->prepare()`:

```php
$result = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}my_table WHERE status = %s",
        $status
    )
);
```

### Code style

- Indentation: **tabs** (per WordPress coding standards).
- Opening braces on the **same line** for control structures:
  ```php
  if ( $condition ) {
      // ...
  }
  ```
- Space after keywords and around operators:
  ```php
  if ( $x === 10 ) { ... }
  $sum = $a + $b;
  ```
- Yoda conditions are **optional** — prioritise consistency within the project.

---

## 4. HTML

- Use **HTML5 semantic elements** — `<header>`, `<main>`, `<nav>`, `<article>`, `<section>`, `<footer>`, `<aside>`.
- Always declare the language: `<html lang="da">` (or `en`, etc.).
- Use the UTF-8 meta tag: `<meta charset="UTF-8">`.
- Self-closing tags are written without a trailing slash in HTML5: `<img>`, `<input>`, `<br>`.
- Attribute order (for readability):
  1. `id`
  2. `class`
  3. `name`, `type`, `value`
  4. `href`, `src`, `alt`
  5. `data-*`
  6. `aria-*`, `role`
- All `<img>` elements **must** have a meaningful `alt` attribute (empty `alt=""` is acceptable for decorative images).
- Avoid inline styles. Move all styling to CSS.

```html
<!-- Bad -->
<div style="color: red; font-size: 14px;">Error</div>

<!-- Good -->
<div class="error-message">Error</div>
```

---

## 5. CSS

### Architecture

- Use **BEM** (Block, Element, Modifier) for class naming or a consistent kebab-case system. Pick one and commit.
  ```css
  /* BEM */
  .card { }
  .card__title { }
  .card--featured { }
  ```
- Organise stylesheets in this order:
  1. Custom properties / variables
  2. Reset / base styles
  3. Typography
  4. Layout (grid, flex wrappers)
  5. Components
  6. Utilities / helpers
  7. Media queries (or co-located with components)

### Custom properties

Prefer CSS custom properties over hard-coded values:

```css
:root {
  --color-primary: #1a56db;
  --color-text: #111827;
  --spacing-md: 1.5rem;
  --border-radius: 4px;
  --font-body: 'Source Serif 4', Georgia, serif;
}
```

### Selectors

- Keep specificity as **low as possible**. Prefer classes over element selectors for styling.
- Avoid `!important` except for utility/helper overrides.
- Avoid styling by ID (`#header { ... }`) — IDs are for anchors and JS hooks.
- Maximum nesting depth: **3 levels** (if using a preprocessor).

### Units

| Use case | Preferred unit |
|---|---|
| Font size | `rem` |
| Spacing (padding/margin) | `rem` or `em` |
| Layout widths | `%`, `fr`, `ch`, `vw` |
| Borders, shadows | `px` |
| Line height | Unitless (`1.5`) |

### Responsive design

- **Mobile-first**: write base styles for small screens, add complexity with `min-width` breakpoints.
- Define breakpoints as custom properties or as named values in a consistent system.
- Test at 320px, 768px, 1024px, and 1440px as a minimum.

---

## 6. JavaScript (ES6+)

### General

- Use **`const` by default**, `let` when reassignment is needed. Never use `var`.
- Use **arrow functions** for callbacks and short functions; use named `function` declarations for top-level logic that benefits from hoisting and stack traces.
- Always use **strict equality** (`===` and `!==`).
- Use **template literals** instead of string concatenation.

```js
// Bad
var greeting = 'Hello, ' + name + '!';

// Good
const greeting = `Hello, ${name}!`;
```

### DOM interaction

- Cache DOM references — don't repeatedly query the DOM.
  ```js
  // Bad (inside loop or repeated calls)
  document.querySelector('.menu').classList.add('open');

  // Good
  const menu = document.querySelector('.menu');
  menu.classList.add('open');
  ```
- Use `data-*` attributes as JS hooks; avoid coupling JS to CSS classes that also carry styling.
  ```html
  <button data-action="toggle-menu">Menu</button>
  ```
  ```js
  document.querySelector('[data-action="toggle-menu"]')
    .addEventListener('click', toggleMenu);
  ```
- Use **event delegation** for dynamic elements:
  ```js
  document.addEventListener('click', (e) => {
    if (e.target.matches('[data-action="remove-item"]')) {
      removeItem(e.target);
    }
  });
  ```

### Modules

- Prefer **ES modules** (`import`/`export`) over IIFEs for new code.
- When authoring for WordPress (enqueued scripts, no bundler), use an IIFE to scope code and avoid polluting the global namespace:
  ```js
  (function () {
    'use strict';
    // module code here
  })();
  ```

### Async code

- Use `async`/`await` over raw `.then()` chains.
- Always handle errors with `try/catch` in async functions.

```js
async function fetchPosts(url) {
  try {
    const response = await fetch(url);
    if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
    return await response.json();
  } catch (error) {
    console.error('fetchPosts failed:', error);
    return null;
  }
}
```

### WordPress AJAX

```js
const data = new URLSearchParams({
  action: 'myplugin_get_data',
  nonce:  mypluginVars.nonce,
  post_id: postId,
});

const response = await fetch(mypluginVars.ajaxUrl, {
  method: 'POST',
  body: data,
});
```

---

## 7. Comments & Documentation

### When to comment

- Explain **why**, not what — the code shows what, comments explain intent.
- Comment complex logic, non-obvious decisions, and known trade-offs.
- Do not comment self-explanatory code.

```php
// Bad
$count = $count + 1; // increment count

// Good
// WordPress clears the cache on post save, so we force a manual
// refresh here to avoid stale data on the first page view.
wp_cache_delete( 'myplugin_featured_posts' );
```

### PHP DocBlocks

All public functions and class methods should have a DocBlock:

```php
/**
 * Returns the featured posts for the homepage widget.
 *
 * @param int $limit Maximum number of posts to return. Default 5.
 * @return WP_Post[] Array of post objects, empty array on failure.
 */
function myplugin_get_featured_posts( int $limit = 5 ): array {
    // ...
}
```

### JS JSDoc

```js
/**
 * Animates an element into view.
 *
 * @param {HTMLElement} el      - The target element.
 * @param {number}      [delay] - Optional delay in ms before animating.
 */
function revealElement(el, delay = 0) { ... }
```

### CSS

```css
/* ==========================================================================
   COMPONENT: Card
   ========================================================================== */

/* Modifier: featured cards use an elevated shadow and accent border */
.card--featured { ... }
```

---

## 8. Version Control (Git)

### Commits

- Write commits in **imperative mood**, present tense: `Add`, `Fix`, `Update`, `Remove`.
- Keep commits atomic — one logical change per commit.
- Format:
  ```
  <type>: <short summary> (max 72 chars)

  Optional body: explain why, not what.
  ```
- Types: `feat`, `fix`, `style`, `refactor`, `docs`, `chore`, `test`

```
feat: add sticky header on scroll

Uses IntersectionObserver on a sentinel element rather than a scroll
listener to avoid jank on mobile.
```

### Branches

| Branch | Purpose |
|---|---|
| `main` | Production-ready code only |
| `develop` | Integration branch |
| `feature/slug` | New features |
| `fix/slug` | Bug fixes |
| `chore/slug` | Tooling, deps, config |

### What NOT to commit

Add these to `.gitignore`:

```
/vendor/
/node_modules/
*.log
.env
.DS_Store
Thumbs.db
wp-config.php
```

---

## 9. Performance & Accessibility

### Performance

- **Enqueue scripts correctly** in WordPress — use `wp_enqueue_script()` with `in_footer: true` where possible.
- Use `loading="lazy"` on below-the-fold images.
- Minify CSS and JS in production.
- Avoid layout thrash — batch DOM reads before writes.
- Prefer CSS transitions/animations over JS-driven ones.

### Accessibility (WCAG 2.1 AA minimum)

- All interactive elements must be keyboard-accessible.
- Use `aria-label` / `aria-labelledby` when visual context alone is insufficient.
- Colour contrast ratio: **4.5:1** for normal text, **3:1** for large text.
- Never remove `:focus` styles without providing a clear visible alternative.
- Use `<button>` for actions, `<a>` for navigation — never the other way around.
- All form fields must have an associated `<label>`.

---

## 10. Tooling & Linting

### Recommended tools

| Tool | Purpose |
|---|---|
| [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) + `WordPress-Core` ruleset | PHP linting |
| [Prettier](https://prettier.io) | HTML / CSS / JS formatting |
| [ESLint](https://eslint.org) | JS linting (`eslint:recommended`) |
| [Stylelint](https://stylelint.io) | CSS linting |
| [EditorConfig](https://editorconfig.org) | Cross-editor whitespace consistency |

### `.editorconfig` baseline

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.php]
indent_style = tab

[*.{js,css,html}]
indent_style = space
indent_size = 2
```

---

*Last updated: April 2026 · Maintained by the project team.*
