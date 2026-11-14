# Screen: Review round (board 2)

The tester reviews one round: before/after screenshots, the pins from the last round, AI checks and AI suggestions, then a verdict per screen area.

## Index

- [Who sees it](#who-sees-it): the tester, and others who read only.
- [Wireframe](#wireframe): the header, the section tabs, the screenshots, and the side panel.
- [Header](#header): back, ticket line, round title, compare switch, full screen.
- [Section tabs](#section-tabs): one tab per screen area, each with a state.
- [Screenshots and pins](#screenshots-and-pins): before and after, human and AI pins, selection.
- [Side panel](#side-panel): pins from the last round, AI suggestions, the verdict.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- Opened from a ticket's Rounds section (`Open round`).
- The person whose turn it is (usually the tester) acts. Others see the same page read-only (product rule; the prototype does not limit it).

## Wireframe

```
┌ header ───────────────────────────────────────────────────────────────────────┐
│ <  SHOP-118 · Totals counted twice on the order overview                       │
│    Round 2 · shot today, 09:12 · Ready for Mia     [Side by side|Before|After] [⤢ Full screen]
│ [Per order  PASSED] [Per product  3 PINS] [Combined view  NOT REVIEWED]  (tabs) │
└───────────────────────────────────────────────────────────────────────────────┘
┌ screenshots ───────────────────────────────┐ ┌ side panel ──────────────────────┐
│ Before · round 1      After · round 2      │ │ Pins from round 1                │
│ ┌──────────────┐      ┌──────────────┐     │ │ ① Product A: planned is 2,850 …  │
│ │   ①      ③   │      │ ⑤  ①   ④  ③  │     │ │    Mia · tester                  │
│ │   ②          │      │    ②         │     │ │    AI Looks fixed: 1,150 now …   │
│ └──────────────┘      └──────────────┘     │ │    [Fixed] [Still wrong]         │
│ Click a pin to see its note. Dashed pins   │ │ AI found 2 more  keep or dismiss │
│ are AI suggestions: they count only after  │ │ ④ Column now left-aligned …      │
│ a person keeps them.                       │ │    [Keep as my pin] [Dismiss]    │
└────────────────────────────────────────────┘ │ Your verdict on Per product      │
                                               │ [Pass][Needs fix][Question]      │
                                               │ Note (optional) [What should Lena know?]│
                                               │ Then: Lena, team lead [Save verdict]│
                                               └──────────────────────────────────┘
```

## Header

- A round back icon button (aria `Back to home`). In the product it goes back to the ticket.
- Ticket line: key (mono) · title (link to the ticket).
- Title: `Round 2` plus muted ` · shot today, 09:12 · Ready for Mia`.
- **Compare** segmented control: `Side by side` (default), `Before`, `After`.
- Outline button `Full screen` (icon) → [full-screen review](full-screen-review.md).

## Section tabs

- `role="tablist"`. One tab per **screen area** the round covers (for example the three views of one page).
- Each tab shows its name and a small state chip:
  - `Passed` (passed colours).
  - `3 pins` (pin colours).
  - `Not reviewed` (`--s-highest`).
- In the prototype only the middle area has content. Other tabs show `This prototype shows the Per Product section only.`

## Screenshots and pins

- Two figures with captions: `Before · round 1` and `After · round 2`. "Before" or "After" alone shows one figure.
- **Markers** are round numbered buttons (aria `Pin N: <text>`), centred on their % position:
  - Human pins: `#b4470a` fill, white 2 px border.
  - AI suggestion pins (after screenshot only): `--ai-container` fill, dashed 2 px `--ai` border, aria `AI suggestion N: …`. A kept AI pin turns into a human pin. A dismissed one disappears.
  - The selected pin gets a 3 px `--primary` ring. Clicking a marker selects it and highlights its row in the side panel.
- The hint under the figures: `Click a pin to see its note. Dashed pins are AI suggestions: they count only after a person keeps them.`

## Side panel

- **Pins from round 1**: one row per earlier pin. The selected row has a `--primary` border and `--s-lowest` background.
  - Number circle, the pin note, and `<reporter> · tester`.
  - An AI check line: an `AI` label plus a short check of the new screenshot, for example `Looks fixed: shows 375 now.`
  - Buttons `Fixed` (tonal) and `Still wrong` (outline).
  - After a choice: a chip `Fixed` (passed colours) or `Still wrong` (error colours), and `Undo`.
- **AI found N more** (`keep or dismiss`), shown while undecided AI suggestions remain:
  - Number, text, `Keep as my pin` (tonal; toast `Pin N added as your pin`), and `Dismiss` (text; toast `Suggestion dismissed`).
- **Your verdict on <area>**:
  - Chips `Pass`, `Needs fix`, `Question` (one choice).
  - Field `Note (optional)`, placeholder `What should Lena know?`.
  - The line `Then: Lena, team lead` (who gets it next).
  - Filled `Save verdict`. Toast `Verdict saved. Lena can see it.`; without a choice: `Pick Pass, Needs fix or Question first.`
- Toasts on this board last 2.8 s.

## Product notes

- **Rules this board implies** (keep them):
  - A round has one or more screen areas. Each area has before/after screenshots, pins, and its own verdict.
  - AI suggestion pins never count until a person keeps them.
  - Each earlier pin gets a per-pin verdict (Fixed / Still wrong).
  - The AI check per pin is advice only.
- Saving verdicts should move the ticket using the same verbs as the ticket page:
  - All areas Pass → `Pass it`.
  - Any `Needs fix` → `Needs changes`.
  - `Question` → asks the lead (pauses the ticket).
  
  The prototype only shows a toast. Decide in the plan.
- **Product differs: ring pins.** The product draws pins as hollow rings (dark outer, white inner outline, number tab, note bubble on hover, focus or select), not the filled `#b4470a` marker above (product owner's decision, 2026-11-14; FR-RND-02 wins). The product also shows each screenshot's optional title and summary above the picture (FR-RND-16), which the prototype does not have.
- The before screenshot is the previous round's after screenshot (or the Round 0 report).
- Screenshots are simulated (`Shot` board, before/after variants). The product shows real uploads.
