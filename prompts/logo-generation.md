# Plateful — Logo Image-Generation Prompt

Brand: **Plateful** — direct online ordering for independent restaurants; warm,
local, modern, friendly. One account, every restaurant; loyalty built in.

Palette: **Teal `#069494`** (primary) and **Crimson `#B22222`** (accent), on
white or near-black. Build depth with lighter/darker shades *of these two
colors* rather than introducing new hues.

**Design direction:** NOT flat, minimal, or corporate-boring. We want a mark with
**dimension and personality** — soft 3D volume, highlights and shadows, a glossy
"pop" that grabs attention. Think a modern, tactile, appetizing app-icon look:
rounded forms that catch the light, a bit of shine, a confident presence. It
should feel like it's leaning toward you, not blending into a wall of flat logos.

> Tip: image models are unreliable at rendering text. Generate the **icon /
> logomark** with the prompts below, then set the word "Plateful" in a real font
> afterward (see "Wordmark" at the bottom). For a text lockup in one shot, use a
> text-capable model (Ideogram, gpt-image / GPT-4o, or Figma with a font).

---

## Primary prompt (icon / logomark) — paste this

```
A bold, dimensional logo mark for "Plateful," a modern online food-ordering brand
for independent restaurants. Concept: a glossy top-down plate rendered with soft
3D depth, and a plump crimson dot at its center that doubles as a location pin —
suggesting "order from local restaurants." Rounded, tactile forms with subtle
volume, soft studio lighting, gentle highlights and a soft drop shadow so the mark
pops off the surface. Vibrant and appetizing, full of personality, confident and
attention-grabbing — not flat, not minimal, not corporate. Two-color brand palette:
teal #069494 as the primary color (use lighter and deeper teal shades for the
shading and highlights) and crimson #B22222 as the accent. Smooth clean surfaces,
polished finish, a hint of glossy sheen. Centered and balanced, still clean enough
to read as an app icon at small sizes. Plain white background, no text. Style:
premium modern 3D-ish app-icon logomark with character. 1:1 square.
```

## Alternate concepts (swap the "Concept:" sentence)

- **Monogram-in-plate:** "a rounded, dimensional lowercase 'p' whose bowl is a
  glossy plate/dish, with a plump crimson accent dot catching the light."
- **Fork + plate ring:** "a teal circular plate ring with real depth and a soft
  inner shadow, a sculpted fork resting across it, one glossy crimson accent."
- **Bowl full:** "a rounded teal bowl with soft 3D volume and a highlight along
  the rim, filled with an abstract sculpted shape, a shiny crimson dot as garnish."
- **Loyalty spark:** "a dimensional plate with a glossy crimson star/spark
  popping off the rim, nodding to loyalty rewards, with a soft cast shadow."

## Technical parameters to include / set

- Dimensional and tactile: soft 3D volume, rounded/beveled forms, subtle inner
  shadows, gentle highlights, a soft drop shadow, a touch of glossy sheen.
- Use **shades and subtle gradients of the two brand colors** for depth; teal
  #069494 primary, crimson #B22222 accent — don't add unrelated colors.
- Energetic, appetizing, characterful — it should "pop," not sit flat.
- Still needs to read as a recognizable shape when shrunk to a favicon / app icon,
  so keep the silhouette clean even though the surface has depth.
- Plain white or transparent background; 1:1 aspect ratio for the icon.
- Ask for 3–4 variations so you can pick.

## Negative prompt / avoid

```
no text, no letters, no words, flat boring corporate minimalism is NOT wanted;
avoid: photorealistic food photography, an actual photo of a real plate of food,
harsh or ugly hard drop shadows, cluttered or busy detail, more than the two brand
colors, generic clip-art fork-and-knife cliché, tiny illegible elements, watermark,
mockup frame, distorted or misspelled text.
```

## Variation set — same concept, different finish (compare these)

Keep the concept identical (teal plate + centered crimson location-pin) and vary
only the style so it's a fair comparison. If your tool supports it, use its
"vary" feature + a fixed seed. Each is 1:1, no text, plain white background.

**A — Semi-flat modern (a little depth, matte):**
```
Modern semi-flat vector logo mark for "Plateful," an online food-ordering brand.
Concept: a top-down plate with a crimson location-pin at its center. Just a hint
of depth — one soft subtle shadow, matte finish, no glossy highlights, clean crisp
edges. Two-color palette: teal #069494 primary, crimson #B22222 accent. Centered,
balanced, reads as a small app icon. Plain white background, no text. 1:1 square.
```

**B — Fully flat (minimal benchmark):**
```
Flat 2D vector logo mark for "Plateful," an online food-ordering brand. Concept: a
top-down plate with a crimson location-pin at its center. Solid colors, no shadows,
no gloss, no gradients, crisp geometric edges, minimal and clean. Two-color
palette: teal #069494, crimson #B22222. Centered, app-icon friendly. Plain white
background, no text. 1:1 square.
```

**C — Matte ceramic 3D (dimensional but not shiny):**
```
Dimensional logo mark for "Plateful," an online food-ordering brand. Concept: a
top-down ceramic plate with a crimson location-pin at its center, rendered with
soft diffuse studio lighting and a realistic matte ceramic finish — soft gentle
shadows, subtle volume, NO shiny glossy highlights. Tactile and premium, soft cast
shadow. Two-color palette: teal #069494 primary, crimson #B22222 accent. Centered,
reads as an app icon. Plain white background, no text. 1:1 square.
```

**D — Bold sticker / chunky (max personality):**
```
Bold sticker-style logo mark for "Plateful," an online food-ordering brand.
Concept: a top-down plate with a crimson location-pin at its center, drawn with a
thick clean outline and a chunky, playful, punchy look. Vibrant, energetic, a
little gloss and a soft drop shadow so it pops. Two-color palette: teal #069494,
crimson #B22222. Centered, app-icon friendly. Plain white background, no text.
1:1 square.
```

Also worth doing: just re-run the **original glossy prompt** once or twice for
random riffs on the version you already like.

## Wordmark (do this after the icon)

Set the word **"Plateful"** in a friendly, characterful typeface with a little
weight and personality — good candidates: **Poppins**, **DM Sans**, **Satoshi**,
or the warmer **Fraunces**. Color it teal `#069494`; optionally give the letters a
subtle highlight/shadow to echo the icon's depth, and tint an accent (a dot or the
crossbar) crimson `#B22222`. Export lockups: (1) icon-only, (2) icon + wordmark
horizontal, (3) wordmark-only, in light- and dark-background versions.

## Deliverables to request/export

- Icon as transparent PNG (512px+) and, if the tool can, a flattened high-res
  version for the site favicon and app icon.
- Horizontal lockup (icon + "Plateful") for the site header/footer.
- A dark-mode variant (the site already supports dark mode: near-black `#0a0a0a`).
```
