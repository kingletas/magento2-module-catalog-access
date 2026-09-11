<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Api;

/**
 * Category ids in, names out — for the whole set, in one query.
 */
interface CategoryNameResolverInterface
{
    /**
     * Separator between path segments.
     */
    public const DEFAULT_SEPARATOR = ' > ';

    /**
     * @return string|null Null when the category does not exist, or exists
     *                     with no name in this store or the default scope.
     */
    public function getName(int $categoryId, ?int $storeId = null): ?string;

    /**
     * @param int[]    $categoryIds Duplicates and non-positive ids are ignored.
     * @param int|null $storeId     Null means the current store.
     *
     * @return array<int, string> Category id => name, misses omitted, in the
     *         order the ids were given.
     */
    public function getNames(array $categoryIds, ?int $storeId = null): array;

    /**
     * The full name path, root categories excluded.
     *
     * @return string Empty when the category does not exist.
     */
    public function getPath(
        int $categoryId,
        ?int $storeId = null,
        string $separator = self::DEFAULT_SEPARATOR
    ): string;

    /**
     * @param int[] $categoryIds
     *
     * @return array<int, string> Category id => path, misses omitted. One
     *         query for the paths and one for every ancestor name in the set,
     *         however many categories are asked for.
     */
    public function getPaths(
        array $categoryIds,
        ?int $storeId = null,
        string $separator = self::DEFAULT_SEPARATOR
    ): array;

    /**
     * The path as its parts, for callers that need to join it themselves or
     * take only the leaf.
     *
     * @return string[] Ordered from the top level down, roots excluded.
     */
    public function getPathSegments(int $categoryId, ?int $storeId = null): array;
}
