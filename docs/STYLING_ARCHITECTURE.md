# Styling architecture

Every page shell loads one generated global stylesheet through
`app/views/partials/app_styles.php`:

- `assets/css/app.css` for public, admin, staff, and client pages

The bundle is generated. Do not edit it directly. Rebuild it with:

```bash
php deploy/build-css.php
```

Deployment rebuilds the bundles automatically. CI and local checks can verify
that committed bundles are current with:

```bash
php deploy/build-css.php --check
```

## Seven source layers

- `tokens.css` — colour, spacing, typography, radius and sizing variables
- `base.css` — reset, document defaults and foundational typography
- `components.css` — controls, panels, tables, tabs, feedback and dialogs
- `layout.css` — public pages, shared portal shell and responsive layout
- `themes.css` — reserved for token-only brand variants; currently light only
- `modules/jewellery.css` — genuinely module-specific jewellery structures
- `print.css` — print-only presentation

New shared components belong in `components.css`; shell and responsive rules
belong in `layout.css`. Theme variants may override variables only. Feature-only
rules must remain scoped to their module root. The former brown, green,
contrast, portal and appearance layers have been removed rather than retained
as competing compatibility styles.
