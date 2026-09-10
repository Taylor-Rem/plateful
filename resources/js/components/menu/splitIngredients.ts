/**
 * Turn a printed description into a first-pass ingredient list, so an
 * owner starts from "Cotto salami, Mortadella, Provolone…" instead of an
 * empty table. Splits on commas, "and", "&", and "with"; drops footnote
 * marks and parentheticals; dedupes case-insensitively.
 */
export function splitIngredients(description: string | null): string[] {
    if (!description) {
        return [];
    }

    const cleaned = description
        .replace(/\([^)]*\)/g, ' ')
        .replace(/[†‡*]/g, ' ')
        // Only the first sentence is the ingredient list; anything after a
        // period is usually a note ("Hot and/or spicy by request.").
        .split(/\.\s|\.$/)[0];

    const seen = new Set<string>();
    const out: string[] = [];

    for (const raw of cleaned.split(
        /,|\s+&\s+|\s+and\s+|\s+with\s+|\s+plus\s+/i,
    )) {
        const name = raw
            .replace(/^\s*(and|with|or)\s+/i, '')
            .replace(/\s+/g, ' ')
            .trim();

        if (name.length < 2 || name.length > 60) {
            continue;
        }

        const key = name.toLowerCase();

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        out.push(name.charAt(0).toUpperCase() + name.slice(1));

        if (out.length >= 25) {
            break;
        }
    }

    return out;
}

/** True when a template is shaped like a swap set: exactly one pick-one group. */
export function isSwapSet(template: App.Data.ItemTemplateData): boolean {
    return template.groups.length === 1 && template.groups[0].isSingleSelect;
}

/** A customization proposal from the analyzer — never priced, off until accepted. */
export type CustomizationSuggestion = {
    name: string;
    kind: 'extra' | 'swap' | 'remove';
    swap_options: string[];
    reason: string;
};

/** The row shape both editors (Ingredients panel, import wizard) share. */
export type IngredientRowLike = {
    name: string;
    is_removable: boolean;
    extra_price: string;
};

export function findRowByName<T extends IngredientRowLike>(
    rows: T[],
    name: string,
): T | undefined {
    const key = name.trim().toLowerCase();

    return rows.find((r) => r.name.trim().toLowerCase() === key);
}

/**
 * Apply an accepted "extra" or "remove" suggestion to a row list, adding the
 * row when the ingredient isn't listed yet. Returns the row it touched.
 * "swap" suggestions are the caller's job — they create a swap set, which
 * the two editors do differently.
 */
export function acceptSimpleSuggestion<T extends IngredientRowLike>(
    rows: T[],
    suggestion: CustomizationSuggestion,
    makeRow: (name: string) => T,
): T {
    let row = findRowByName(rows, suggestion.name);

    if (!row) {
        row = makeRow(suggestion.name);
        // An extra on something not in the item ("Add avocado") is offered
        // but never "left out" — it isn't included to begin with.
        row.is_removable = suggestion.kind === 'remove';
        rows.push(row);
    }

    if (suggestion.kind === 'remove') {
        row.is_removable = true;
    }

    return row;
}

/**
 * The row a "swap" proposal attaches to: the first swap option is the
 * printed ingredient ("Provolone"), while the proposal's own name is the
 * set ("Cheese"). Adds the row when the ingredient isn't listed yet.
 */
export function rowForSwapSuggestion<T extends IngredientRowLike>(
    rows: T[],
    suggestion: CustomizationSuggestion,
    makeRow: (name: string) => T,
): T {
    const ingredientName = suggestion.swap_options[0] ?? suggestion.name;
    let row = findRowByName(rows, ingredientName);

    if (!row) {
        row = makeRow(ingredientName);
        rows.push(row);
    }

    return row;
}
