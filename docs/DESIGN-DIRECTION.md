# PharmaSure Design Direction

## North star

PharmaSure should feel like a clinical operations instrument: calm under pressure, dense without being crowded, precise without becoming sterile, and unmistakably designed for pharmacy work.

The authenticated application is not a marketing site. Its visual quality comes from information architecture, typography, alignment, state clarity and interaction reliability—not large gradients, oversized headings or repeated floating cards.

## Visual character

- **Structured:** visible alignment, section rules and consistent control geometry.
- **Crisp:** low radii, restrained shadows and high-quality 1px borders.
- **Operational:** compact tables, tabular numerals, explicit timestamps and branch context.
- **Clinical:** neutral surfaces with teal reserved for navigation and action, green for confirmed safe states, amber for attention and red for blocking risk.
- **Recognizable:** the dark ink navigation rail, teal instrument line and compact PharmaSure monogram form a repeatable signature.

## Interface rules

1. Default to a page header, operational context, primary action and then content.
2. Use cards only where the boundary carries meaning. Do not wrap every section in a card.
3. Prefer rules, spacing and background shifts over shadows.
4. Use no more than one primary action per region.
5. Use sentence case for actions and headings; uppercase is reserved for short metadata labels.
6. Use tabular numerals for quantities, currency, claims, batch numbers and timestamps.
7. Pair every status color with text or an icon; color is never the only signal.
8. Keep destructive actions visually separated and require explicit confirmation and reason where appropriate.
9. Preserve visible keyboard focus, 44px critical touch targets and reduced-motion behavior.
10. Design empty, loading, error, offline, stale and permission-denied states alongside the successful state.

## Geometry and spacing

- Base spacing unit: `4px`.
- Control height: `40px` compact, `44px` standard.
- Surface radius: `3px`; prominent panels may use `6px`.
- Border: `1px solid #cdd8dc` on operational surfaces.
- Content width is driven by the task. Tables and POS use the available viewport; forms use readable bounded columns.

## Type

- UI family: `Aptos`, `Segoe UI Variable`, `Segoe UI`, system sans-serif.
- Data and identifiers: `Cascadia Mono`, `SFMono-Regular`, `Consolas`, monospace.
- Body: `14px–15px` in dense operational views.
- Page title: `24px–28px`, never marketing-scale inside the application.
- Use weight and spacing before increasing type size.

## Color roles

| Token | Value | Role |
|---|---:|---|
| Ink 950 | `#071f2a` | Navigation depth |
| Ink 900 | `#0b2937` | Primary text and shell |
| Teal 700 | `#087f8c` | Primary action and active context |
| Teal 050 | `#edf7f6` | Selected and informational surface |
| Green 700 | `#16875d` | Confirmed/safe state |
| Amber 700 | `#9a6700` | Attention/expiry/review state |
| Red 700 | `#b42318` | Blocking/destructive state |
| Slate 500 | `#60747c` | Secondary text |
| Slate 200 | `#cdd8dc` | Operational borders |
| Canvas | `#f4f7f7` | Application background |

## Product language

Preferred product description:

> Enterprise multi-tenant pharmacy operations platform for inventory, dispensing, point of sale, claims, reporting and multi-branch control.

Claims must be demonstrable in code and deployment. The design should communicate trust through clarity and evidence rather than compliance badges or inflated security language.
