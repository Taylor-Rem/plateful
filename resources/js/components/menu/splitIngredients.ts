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
