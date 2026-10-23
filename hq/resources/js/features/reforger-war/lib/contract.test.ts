import { WarCommandSchema } from '@/lib/schemas/reforger';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The desk the server builds (BuildWarCommandDataAction), as the contract test in
 * tests/Feature/ReforgerWarHqTest.php writes it: every list with rows. The PHP test fails when
 * the desk changes shape; this one fails when the page's schema no longer reads it.
 */
const desk: unknown = JSON.parse(readFileSync(resolve(import.meta.dirname, '../__fixtures__/war-desk.json'), 'utf8'));

describe('war desk contract', () => {
    it('reads the desk the server builds', () => {
        const parsed = WarCommandSchema.safeParse(desk);

        expect(parsed.success ? [] : parsed.error.issues.slice(0, 5)).toEqual([]);
    });

    it('refuses a desk with a field it does not know', () => {
        expect(WarCommandSchema.safeParse({ ...(desk as object), surprise: 1 }).success).toBe(false);
    });
});
