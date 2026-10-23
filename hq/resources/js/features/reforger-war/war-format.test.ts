import { describe, expect, it } from 'vitest';
import { howHeDied, weaponName } from './war-format';

describe('howHeDied', () => {
    it('says who, with what and from how far', () => {
        expect(howHeDied({ cause: 'enemy', by: 'Pvt Ivanov', weapon: 'Rifle_AK74_GP25', metres: 140 })).toBe('by Pvt Ivanov · AK74 GP25 · 140 m');
    });

    it('names friendly fire and artillery, without the shell for a weapon', () => {
        expect(howHeDied({ cause: 'friendly', by: 'Cpl Smith', weapon: null, metres: 20 })).toBe('friendly fire from Cpl Smith · 20 m');
        expect(howHeDied({ cause: 'fire', by: null, weapon: 'Shell_82mm', metres: null })).toBe('under artillery');
    });

    it('says a man who bled out died of his wounds', () => {
        expect(howHeDied({ cause: 'wounds', by: null, weapon: null, metres: null })).toBe('of his wounds');
    });

    it('says nothing when the game did not', () => {
        expect(howHeDied(null)).toBeNull();
        expect(howHeDied({ cause: 'other', by: null, weapon: null, metres: null })).toBeNull();
    });

    it('drops the prefab kind from a weapon name', () => {
        expect(weaponName('MG_PKM')).toBe('PKM');
        expect(weaponName('Launcher_RPG7')).toBe('RPG7');
    });
});
