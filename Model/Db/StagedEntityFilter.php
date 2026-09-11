<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * The two things every hand-written catalogue query has to get right on
 * Adobe Commerce, in one place.
 */
class StagedEntityFilter
{
    private const VERSION_COLUMN = 'created_in';

    /** @var array<string, string> Entity interface => link field. */
    private array $linkFields = [];

    /** @var array<string, bool> Table => whether it carries version columns. */
    private array $staged = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * @param string $entityInterface e.g. `ProductInterface::class`.
     */
    public function getLinkField(string $entityInterface): string
    {
        if (isset($this->linkFields[$entityInterface])) {
            return $this->linkFields[$entityInterface];
        }

        try {
            $linkField = (string) $this->metadataPool
                ->getMetadata($entityInterface)
                ->getLinkField();
        } catch (\Exception) {
            // An entity with no metadata registered is not staged, by
            // definition — it has no metadata to be staged by.
            $linkField = 'entity_id';
        }

        return $this->linkFields[$entityInterface] = $linkField === '' ? 'entity_id' : $linkField;
    }

    /**
     * Narrow a select to the version of each entity that is live right now.
     *
     * @param string $alias The alias the entity table carries in this select.
     * @param int    $now   Unix timestamp; staging's version ids are timestamps.
     */
    public function applyCurrentVersion(Select $select, string $alias, string $table, ?int $now = null): void
    {
        if (!$this->isStaged($table)) {
            return;
        }

        $now = $now ?? time();

        $select->where($alias . '.created_in <= ?', $now)
            ->where($alias . '.updated_in > ?', $now);
    }

    public function isStaged(string $table): bool
    {
        if (isset($this->staged[$table])) {
            return $this->staged[$table];
        }

        return $this->staged[$table] = $this->resourceConnection
            ->getConnection()
            ->tableColumnExists($table, self::VERSION_COLUMN);
    }
}
