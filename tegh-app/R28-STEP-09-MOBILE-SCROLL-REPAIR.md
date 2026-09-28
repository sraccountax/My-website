# Tegh R28 Step 09 — Mobile Scroll Repair

Active cache revision: `5990-r28-s9-mobile-scroll-repair`

## Problem

The phone shell bounded the application between its fixed top bar and bottom
navigation, while R27 also forced the stage and document to `overflow:hidden`.
Pages that did not establish a correctly sized inner scroller therefore could
not respond to vertical touch swipes.

## Repair

- Keeps the document locked so fixed mobile navigation does not drift.
- Makes `.stage` the single vertical mobile scroll owner.
- Enables touch panning and iOS momentum scrolling.
- Releases fixed-height and hidden-overflow constraints from page wrappers.
- Preserves horizontal containment and internal table scrolling.
- Locks the stage again while the mobile drawer is open.
- Retains reduced-motion behaviour.

Desktop one-screen layouts and their internal report/register scrollers are
unchanged because the repair is limited to viewports at or below 820px.
