# Screen: Full-screen review (board 3)

The same review round, one pin at a time, on a dark full screen. It is for focus on a big monitor.

## Who sees it

- Opened from the review round's `Full screen` button.
- It always uses dark colours (fixed dark surface `#0e1513`), whatever the app theme.

## Wireframe

```
┌───────────────────────────────────────────────────────────────────────────┐
│ [⤡ Exit full screen]  SHOP-118 · Round 2 · Per product · section 2 of 3  [After|Before] │
├───────────────────────────────────────────────────────────────────────────┤
│              ┌────────────────────────────────────────────┐               │
│              │  screenshot, max width min(1200 px, screen │               │
│              │  fits height), 12 px radius, deep shadow   │               │
│              │     ⑤   (①)  ④   ③                         │               │
│              └────────────────────────────────────────────┘               │
│   ┌──────────────────────────────────────────────────────────────────┐    │
│   │ (<)  PIN 1 OF 5 · MIA                         [Fixed] [Still wrong] (>) │
│   │      Product A: planned is 2,850, but the orders only plan 1,150.  │    │
│   └──────────────────────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────────────────────┘
```

## Elements and rules

- **Header**:
  - Outline button `Exit full screen` (back to the review round).
  - A line `<key> · Round 2 · <area name> · section 2 of 3`.
  - A segmented control `After` (default) / `Before`.
- **Screenshot**: the "after" or "before" picture, with markers 34 px round.
  - Human pins: `#b4470a`.
  - AI pins: AI colours, dashed.
  - The current pin has a 4 px `--primary` ring. Clicking a marker makes it current.
  - In `Before`, only human pins show (AI suggestions belong to the new screenshot).
- **Pin bar** (`aria-live="polite"`, max 720 px, rounded 18 px):
  - Previous icon button (aria `Previous pin`) and next icon button (aria `Next pin`). They wrap around at both ends.
  - Label (12 px bold):
    - `PIN n OF m · MIA` for human pins.
    - `AI SUGGESTION n OF m` (in `--ai` colour) for AI pins.
  - The pin text (16 px).
  - Two buttons, which save the choice and move to the next pin:
    - Human pin: `Fixed` (tonal) and `Still wrong` (text).
    - AI pin: `Keep` and `Dismiss`.
- Switching After/Before resets to the first pin.

## Product notes

- Choices here must be the same records as on the review round board (one source of truth). The prototype only moves to the next pin.
- Add keyboard keys: ← / → for pins, F for Fixed, W for Still wrong, Esc to exit. These are not in the prototype but fit its purpose.
