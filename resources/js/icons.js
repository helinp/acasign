// Icônes Tabler (resources/icons/tabler) pour les éléments créés en JavaScript : même rendu que le composant Blade <x-icon>.
const sources = import.meta.glob('../icons/tabler/*.svg', { query: '?raw', import: 'default', eager: true });

/** Balise <svg> décorative (aria-hidden) ; le libellé reste porté par le texte ou l'aria-label du bouton. */
export function icon(name, extraClass = '') {
    const svg = sources[`../icons/tabler/${name}.svg`] ?? '';
    return svg.replace(/class="[^"]*"/, `class="${`icon ${extraClass}`.trim()}" aria-hidden="true" focusable="false"`);
}
