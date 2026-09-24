/** Kategorie v rozbalovacím výběru odsazená podle hloubky stromu (nezlomitelné mezery). */
export function indentedCategoryLabel(category: { name: string; depth: number }): string {
  return `${'  '.repeat(category.depth)}${category.name}`
}
